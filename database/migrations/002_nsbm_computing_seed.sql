-- ULearn: optional starter catalogue based on NSBM's published Faculty of Computing programmes.
-- Source: https://www.nsbm.ac.lk/degree-programmes/
-- Run AFTER 001_awarding_universities.sql. Safe to re-run; no existing records are deleted or modified.
-- Programme codes below are ULearn INTERNAL identifiers, not official NSBM codes.
-- No intake/curriculum mapping is inserted because intake-specific semester details are not verified.

INSERT INTO awarding_universities (name, code, is_active)
SELECT 'NSBM Green University', 'NSBM', 1
WHERE NOT EXISTS (SELECT 1 FROM awarding_universities WHERE code = 'NSBM');
INSERT INTO awarding_universities (name, code, is_active)
SELECT 'University of Plymouth', 'PLYMOUTH', 1
WHERE NOT EXISTS (SELECT 1 FROM awarding_universities WHERE code = 'PLYMOUTH');
INSERT INTO awarding_universities (name, code, is_active)
SELECT 'Victoria University', 'VICTORIA', 1
WHERE NOT EXISTS (SELECT 1 FROM awarding_universities WHERE code = 'VICTORIA');

-- NSBM-awarded Faculty of Computing degrees
INSERT INTO academic_degrees (name, code, awarding_university_id, is_active)
SELECT p.name, p.code, u.id, 1
FROM (
 SELECT 'BSc (Hons) in Data Science' AS name, 'NSBM-DS' AS code UNION ALL
 SELECT 'BSc (Hons) in Computer Networks', 'NSBM-CN' UNION ALL
 SELECT 'BSc (Hons) in Computer Science', 'NSBM-CS' UNION ALL
 SELECT 'BSc (Hons) in Software Engineering', 'NSBM-SE' UNION ALL
 SELECT 'BSc in Management Information Systems (Special)', 'NSBM-MIS'
) p JOIN awarding_universities u ON u.code = 'NSBM'
WHERE NOT EXISTS (SELECT 1 FROM academic_degrees d WHERE d.code = p.code);

-- University of Plymouth-awarded Faculty of Computing degrees
INSERT INTO academic_degrees (name, code, awarding_university_id, is_active)
SELECT p.name, p.code, u.id, 1
FROM (
 SELECT 'BSc (Hons) Technology Management' AS name, 'PLY-TM' AS code UNION ALL
 SELECT 'BSc (Hons) Computer Science', 'PLY-CS' UNION ALL
 SELECT 'BSc (Hons) Computer Networks', 'PLY-CN' UNION ALL
 SELECT 'BSc (Hons) Computer Security', 'PLY-CSEC' UNION ALL
 SELECT 'BSc (Hons) Software Engineering', 'PLY-SE' UNION ALL
 SELECT 'BSc (Hons) in Data Science', 'PLY-DS' UNION ALL
 SELECT 'BSc (Hons) Artificial Intelligence', 'PLY-AI'
) p JOIN awarding_universities u ON u.code = 'PLYMOUTH'
WHERE NOT EXISTS (SELECT 1 FROM academic_degrees d WHERE d.code = p.code);

-- Victoria University-awarded Faculty of Computing degree
INSERT INTO academic_degrees (name, code, awarding_university_id, is_active)
SELECT 'Bachelor of Information Technology (Major in Cyber Security)', 'VIC-BIT-CYBER', u.id, 1
FROM awarding_universities u WHERE u.code = 'VICTORIA'
AND NOT EXISTS (SELECT 1 FROM academic_degrees WHERE code = 'VIC-BIT-CYBER');
