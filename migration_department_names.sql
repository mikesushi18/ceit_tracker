-- Update existing department records to the current official names.
-- Safe to run more than once.
USE ceit_tracker;

UPDATE departments
SET name = CASE code
    WHEN 'DAFE' THEN 'Department of Agricultural and Food Engineering'
    WHEN 'DCEA' THEN 'Department of Civil Engineering and Allied Studies'
    WHEN 'DCEEE' THEN 'Department of Computer, Electronic, and Electrical Engineering'
    WHEN 'DIET' THEN 'Department of Industrial Engineering and Technology'
    WHEN 'DIT' THEN 'Department of Information Technology'
    ELSE name
END
WHERE code IN ('DAFE', 'DCEA', 'DCEEE', 'DIET', 'DIT');
