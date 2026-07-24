-- Manual enquiry follow-up, confirmation, and booking-conversion workflow.
-- Idempotent for MariaDB 10.4+. This migration never drops or deletes data.

USE travel_crm;

ALTER TABLE enquiries
    ADD COLUMN IF NOT EXISTS service_type VARCHAR(50) NULL AFTER source,
    ADD COLUMN IF NOT EXISTS from_location VARCHAR(150) NULL AFTER service_type,
    ADD COLUMN IF NOT EXISTS to_location VARCHAR(150) NULL AFTER from_location,
    ADD COLUMN IF NOT EXISTS travel_date DATE NULL AFTER to_location,
    ADD COLUMN IF NOT EXISTS passenger_count INT NOT NULL DEFAULT 1 AFTER travel_date,
    ADD COLUMN IF NOT EXISTS priority VARCHAR(20) NOT NULL DEFAULT 'Medium' AFTER passenger_count,
    ADD COLUMN IF NOT EXISTS next_follow_up_at DATETIME NULL AFTER priority,
    ADD COLUMN IF NOT EXISTS confirmed_at DATETIME NULL AFTER next_follow_up_at,
    ADD COLUMN IF NOT EXISTS final_service_date DATE NULL AFTER confirmed_at,
    ADD COLUMN IF NOT EXISTS final_selling_amount DECIMAL(12,2) NULL AFTER final_service_date,
    ADD COLUMN IF NOT EXISTS confirmation_note TEXT NULL AFTER final_selling_amount,
    ADD COLUMN IF NOT EXISTS booking_id INT NULL AFTER confirmation_note;

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS enquiry_id INT NULL AFTER id;

CREATE TABLE IF NOT EXISTS enquiry_follow_ups (
    id INT NOT NULL AUTO_INCREMENT,
    enquiry_id INT NOT NULL,
    contacted_at DATETIME NOT NULL,
    contact_method VARCHAR(30) NOT NULL,
    discussion_note TEXT NOT NULL,
    quoted_amount DECIMAL(12,2) NULL,
    next_follow_up_at DATETIME NULL,
    result VARCHAR(50) NOT NULL,
    created_by VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_enquiry_follow_ups_enquiry_id (enquiry_id),
    KEY idx_enquiry_follow_ups_next_date (next_follow_up_at),
    CONSTRAINT fk_enquiry_follow_ups_enquiry
        FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE enquiries
    ADD INDEX IF NOT EXISTS idx_enquiries_source (source),
    ADD INDEX IF NOT EXISTS idx_enquiries_status (status),
    ADD INDEX IF NOT EXISTS idx_enquiries_next_follow_up (next_follow_up_at),
    ADD INDEX IF NOT EXISTS idx_enquiries_assigned_user (assigned_user),
    ADD INDEX IF NOT EXISTS idx_enquiries_booking_id (booking_id);

ALTER TABLE bookings
    ADD UNIQUE INDEX IF NOT EXISTS uq_bookings_enquiry_id (enquiry_id);

-- Backfill structured service type without altering the original requirement text.
UPDATE enquiries
SET service_type = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(description, ']', 1), ':', -1))
WHERE (service_type IS NULL OR service_type = '')
  AND description LIKE '[Service Type:%]%';

-- Backward-compatible status mapping applies only to manual/legacy-direct records.
UPDATE enquiries
SET status = 'Follow-up'
WHERE (LOWER(COALESCE(source, '')) IN ('manual', 'direct') OR COALESCE(source, '') = '')
  AND status IN ('Seen', 'Replied');

-- A legacy Booked enquiry is converted only when it already has a verified booking link.
UPDATE enquiries
SET status = 'Converted'
WHERE (LOWER(COALESCE(source, '')) IN ('manual', 'direct') OR COALESCE(source, '') = '')
  AND status = 'Booked'
  AND booking_id IS NOT NULL;

