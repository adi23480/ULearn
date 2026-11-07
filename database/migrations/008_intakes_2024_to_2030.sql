-- ULearn: 3 intakes per year, 2024 through 2030 (24.1 ... 30.3)
-- Run against the EXISTING ulearn database. Safe to rerun.
-- Requires migration 006_student_system.sql for academic_intake_progression.
-- Does not delete or overwrite existing student, lecturer, or curriculum data.
-- New intakes are initially active, as requested. Disable future intakes
-- in Admin Academic Structure until applications actually open if desired.

INSERT INTO academic_batches (batch_code, intake_year, intake_number, is_active)
VALUES
 ('24.1',2024,1,1),('24.2',2024,2,1),('24.3',2024,3,1),
 ('25.1',2025,1,1),('25.2',2025,2,1),('25.3',2025,3,1),
 ('26.1',2026,1,1),('26.2',2026,2,1),('26.3',2026,3,1),
 ('27.1',2027,1,1),('27.2',2027,2,1),('27.3',2027,3,1),
 ('28.1',2028,1,1),('28.2',2028,2,1),('28.3',2028,3,1),
 ('29.1',2029,1,1),('29.2',2029,2,1),('29.3',2029,3,1),
 ('30.1',2030,1,1),('30.2',2030,2,1),('30.3',2030,3,1)
ON DUPLICATE KEY UPDATE batch_code = VALUES(batch_code);

-- Link each intake to every currently active degree with an active university.
-- This assumes all degrees offer all 21 intakes; review real offerings in admin.
INSERT IGNORE INTO academic_degree_intakes (degree_id, batch_id, is_active)
SELECT d.id, b.id, 1
FROM academic_degrees AS d
JOIN awarding_universities AS u
  ON u.id = d.awarding_university_id AND u.is_active = 1
CROSS JOIN academic_batches AS b
WHERE d.is_active = 1 AND b.intake_year BETWEEN 2024 AND 2030
  AND b.intake_number BETWEEN 1 AND 3
;

-- Set progression defaults only for links without a progression record.
-- Existing progression values are preserved; future intake progression
-- must be advanced by admin when the academic calendar changes.
INSERT IGNORE INTO academic_intake_progression (degree_id, batch_id, study_year, semester)
SELECT adi.degree_id, adi.batch_id, 1, 1
FROM academic_degree_intakes AS adi
JOIN academic_batches AS b ON b.id = adi.batch_id
WHERE b.intake_year BETWEEN 2024 AND 2030
  AND b.intake_number BETWEEN 1 AND 3;

-- Verification queries (optional):
-- SELECT batch_code FROM academic_batches WHERE intake_year BETWEEN 2024 AND 2030 ORDER BY intake_year,intake_number;
-- SELECT d.name, COUNT(*) AS linked_intakes FROM academic_degrees d JOIN academic_degree_intakes adi ON adi.degree_id=d.id JOIN academic_batches b ON b.id=adi.batch_id WHERE b.intake_year BETWEEN 2024 AND 2030 GROUP BY d.id,d.name;
