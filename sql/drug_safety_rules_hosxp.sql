ALTER TABLE drug_safety_rules
    ADD COLUMN drug_icode VARCHAR(20) NULL DEFAULT NULL,
    ADD COLUMN drug_name VARCHAR(255) NULL DEFAULT NULL;

UPDATE drug_safety_rules r
INNER JOIN drugitems d ON r.drug_id = d.id
SET r.drug_icode = d.icode,
    r.drug_name = d.name
WHERE (r.drug_icode IS NULL OR TRIM(r.drug_icode) = '')
  AND d.icode IS NOT NULL
  AND TRIM(d.icode) <> '';
