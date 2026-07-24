-- Add Booking: Flight, Hotel, Other Service and Visa document fields.
-- The application also applies these columns safely through includes/db.php.

ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS document_type VARCHAR(30) DEFAULT 'ticket',
  ADD COLUMN IF NOT EXISTS hotel_country VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS hotel_city VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS hotel_address VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS hotel_nights_count INT NULL,
  ADD COLUMN IF NOT EXISTS hotel_meal_plan VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS hotel_adults_count INT NULL,
  ADD COLUMN IF NOT EXISTS hotel_children_count INT NULL,
  ADD COLUMN IF NOT EXISTS hotel_guest_names TEXT NULL,
  ADD COLUMN IF NOT EXISTS hotel_booking_details TEXT NULL,
  ADD COLUMN IF NOT EXISTS visa_entry_type VARCHAR(50) NULL,
  ADD COLUMN IF NOT EXISTS visa_duration_days INT NULL,
  ADD COLUMN IF NOT EXISTS visa_issue_place VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS visa_uid_no VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS visa_full_name VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS visa_nationality VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS visa_place_of_birth VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS visa_date_of_birth DATE NULL,
  ADD COLUMN IF NOT EXISTS visa_passport_type VARCHAR(50) NULL,
  ADD COLUMN IF NOT EXISTS visa_passport_no VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS visa_profession VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS other_service_name VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS other_reference_no VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS other_service_date DATE NULL,
  ADD COLUMN IF NOT EXISTS other_details TEXT NULL;