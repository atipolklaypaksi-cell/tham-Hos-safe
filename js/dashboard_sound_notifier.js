/**
 * Global dashboard sound notifier — polls stage counts and plays AI voice alerts
 * on any page that includes sidebar.php (settings from dashboardtime.php localStorage).
 */
(function () {
    const STAGES = ['scan', 'key', 'prep', 'dispense'];
    const STORAGE_KEY = 'dashboardCounts';
    const POLL_INTERVAL_MS = 15000;

    const STAGE_SPEECH = {
        scan: 'New order scanned.',
        key: 'Order keyed.',
        prep: 'Preparation done.',
        dispense: 'Medication dispensed.',
    };

    const STAGE_TEST_SPEECH = {
        scan: 'Attention please. New order scanned.',
        key: 'Attention please. Order keyed.',
        prep: 'Attention please. Preparation done.',
        dispense: 'Attention please. Medication dispensed.',
    };

    let systemVoices = [];

    function initVoices() {
        if ('speechSynthesis' in window) {
            systemVoices = window.speechSynthesis.getVoices();
        }
    }

    if ('speechSynthesis' in window) {
        initVoices();
        if (speechSynthesis.onvoiceschanged !== undefined) {
            speechSynthesis.onvoiceschanged = initVoices;
        }
    }

    function playDingDong(callback) {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) {
            if (callback) callback();
            return;
        }

        try {
            const ctx = new AudioContext();
            const osc = ctx.createOscillator();
            const gainNode = ctx.createGain();

            osc.connect(gainNode);
            gainNode.connect(ctx.destination);
            osc.type = 'sine';

            osc.frequency.setValueAtTime(659.25, ctx.currentTime);
            gainNode.gain.setValueAtTime(0, ctx.currentTime);
            gainNode.gain.linearRampToValueAtTime(0.5, ctx.currentTime + 0.05);
            gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.4);

            osc.frequency.setValueAtTime(523.25, ctx.currentTime + 0.4);
            gainNode.gain.linearRampToValueAtTime(0.5, ctx.currentTime + 0.45);
            gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 1.2);

            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 1.2);

            setTimeout(function () {
                if (callback) callback();
            }, 1200);
        } catch (error) {
            console.error('Audio API Error:', error);
            if (callback) callback();
        }
    }

    function speakAI(text) {
        if (!('speechSynthesis' in window)) return;

        window.speechSynthesis.cancel();

        const msg = new SpeechSynthesisUtterance(text);
        msg.lang = 'en-US';
        msg.rate = 1.0;
        msg.pitch = 1.1;

        if (systemVoices.length > 0) {
            const engVoice = systemVoices.find(function (v) {
                return v.lang.includes('en-US') || v.lang.includes('en-GB') || v.name.includes('English');
            });
            if (engVoice) msg.voice = engVoice;
        }

        window.speechSynthesis.speak(msg);
    }

    window.testSpecificVoice = function (stage) {
        const text = STAGE_TEST_SPEECH[stage];
        if (!text) return;
        playDingDong(function () {
            speakAI(text);
        });
    };

    function isSoundEnabled(stage) {
        return localStorage.getItem('soundToggle_' + stage) === 'true';
    }

    function initToggleSwitches() {
        document.querySelectorAll('.snd-toggle').forEach(function (checkbox) {
            const stage = checkbox.value;
            const savedState = localStorage.getItem('soundToggle_' + stage);
            if (savedState !== null) {
                checkbox.checked = savedState === 'true';
            }
            checkbox.addEventListener('change', function () {
                localStorage.setItem('soundToggle_' + stage, this.checked);
            });
        });
    }

    function processCounts(newCounts) {
        let storedCounts = null;
        try {
            storedCounts = JSON.parse(localStorage.getItem(STORAGE_KEY));
        } catch (e) {
            storedCounts = null;
        }

        const speechQueue = [];

        if (storedCounts !== null) {
            STAGES.forEach(function (stage) {
                if (newCounts[stage] > storedCounts[stage] && isSoundEnabled(stage)) {
                    if (STAGE_SPEECH[stage]) speechQueue.push(STAGE_SPEECH[stage]);
                }
            });
        }

        localStorage.setItem(STORAGE_KEY, JSON.stringify(newCounts));

        if (speechQueue.length > 0) {
            setTimeout(function () {
                playDingDong(function () {
                    speakAI(speechQueue.join(' ... '));
                });
            }, 300);
        }
    }

    function pollCounts() {
        fetch('fetch_dashboard_counts_api.php', { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (data.success && data.counts) {
                    processCounts(data.counts);
                }
            })
            .catch(function (err) {
                console.warn('Dashboard sound notifier:', err);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initToggleSwitches);
    } else {
        initToggleSwitches();
    }

    pollCounts();
    setInterval(pollCounts, POLL_INTERVAL_MS);
})();
