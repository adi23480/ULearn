-- Apply after migration 013. Availability windows do not impose a consultation duration.
ALTER TABLE consultation_date_slots ADD COLUMN end_time TIME NULL AFTER start_time;
UPDATE consultation_date_slots SET end_time = ADDTIME(start_time, '01:00:00') WHERE end_time IS NULL;
