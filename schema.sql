-- Create database if not exists
CREATE DATABASE IF NOT EXISTS travel_crm DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE travel_crm;

-- Table structure for users
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role VARCHAR(50) DEFAULT 'agent'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default users (password for both is plain text, which is accepted by login.php)
INSERT INTO users (name, email, password, role) VALUES
('Admin User', 'admin@travelcrm.com', 'admin', 'admin'),
('Agent User', 'agent@travelcrm.com', 'agent', 'agent')
ON DUPLICATE KEY UPDATE id=id;

-- Table structure for enquiries
CREATE TABLE IF NOT EXISTS enquiries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(255) NOT NULL,
  mobile VARCHAR(50) DEFAULT '',
  email VARCHAR(255) DEFAULT '',
  subject VARCHAR(255) DEFAULT '',
  description TEXT,
  source VARCHAR(100) DEFAULT 'Direct',
  service_type VARCHAR(50) DEFAULT NULL,
  from_location VARCHAR(150) DEFAULT NULL,
  to_location VARCHAR(150) DEFAULT NULL,
  travel_date DATE DEFAULT NULL,
  passenger_count INT NOT NULL DEFAULT 1,
  priority VARCHAR(20) NOT NULL DEFAULT 'Medium',
  next_follow_up_at DATETIME DEFAULT NULL,
  confirmed_at DATETIME DEFAULT NULL,
  final_service_date DATE DEFAULT NULL,
  final_selling_amount DECIMAL(12,2) DEFAULT NULL,
  confirmation_note TEXT DEFAULT NULL,
  booking_id INT DEFAULT NULL,
  status VARCHAR(50) DEFAULT 'New',
  assigned_user VARCHAR(255) DEFAULT '',
  created_by VARCHAR(255) DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_enquiries_source (source),
  INDEX idx_enquiries_status (status),
  INDEX idx_enquiries_next_follow_up (next_follow_up_at),
  INDEX idx_enquiries_assigned_user (assigned_user),
  INDEX idx_enquiries_booking_id (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for customers
CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  mobile VARCHAR(100) DEFAULT '',
  email VARCHAR(255) DEFAULT '',
  address TEXT DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for bookings
CREATE TABLE IF NOT EXISTS bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enquiry_id INT DEFAULT NULL,
  serial_no VARCHAR(100) DEFAULT '',
  booking_date DATE DEFAULT NULL,
  passenger_name VARCHAR(255) DEFAULT '',
  customer_name VARCHAR(255) DEFAULT '',
  customer_type VARCHAR(100) DEFAULT '',
  from_city VARCHAR(100) DEFAULT '',
  to_city VARCHAR(100) DEFAULT '',
  departure_date DATE DEFAULT NULL,
  departure_time VARCHAR(50) DEFAULT '',
  arrival_date DATE DEFAULT NULL,
  arrival_time VARCHAR(50) DEFAULT '',
  pnr VARCHAR(100) DEFAULT '',
  ticket_number VARCHAR(100) DEFAULT '',
  flight_number VARCHAR(100) DEFAULT '',
  airline_name VARCHAR(100) DEFAULT '',
  flight_class VARCHAR(100) DEFAULT '',
  terminal VARCHAR(50) DEFAULT '',
  seat_number VARCHAR(50) DEFAULT '',
  baggage VARCHAR(100) DEFAULT '',
  booking_ref VARCHAR(100) DEFAULT '',
  fare_basis VARCHAR(100) DEFAULT '',
  service_type VARCHAR(100) DEFAULT 'Flight',
  supplier_name VARCHAR(255) DEFAULT '',
  buying_cost DECIMAL(10,2) DEFAULT 0.00,
  selling_cost DECIMAL(10,2) DEFAULT 0.00,
  profit DECIMAL(10,2) DEFAULT 0.00,
  payment_method VARCHAR(100) DEFAULT '',
  assigned_user VARCHAR(255) DEFAULT '',
  status VARCHAR(50) DEFAULT 'Booked',
  remarks TEXT DEFAULT NULL,
  ticket_path VARCHAR(255) DEFAULT NULL,
  created_by VARCHAR(255) DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bookings_enquiry_id (enquiry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for booking_attachments
CREATE TABLE IF NOT EXISTS booking_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_type VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for tasks
CREATE TABLE IF NOT EXISTS tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  assigned_user_id INT DEFAULT NULL,
  status VARCHAR(50) DEFAULT 'Pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for activity_log
CREATE TABLE IF NOT EXISTS activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(255) NOT NULL,
  action VARCHAR(255) NOT NULL,
  module VARCHAR(100) DEFAULT '',
  activity_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table structure for enquiry_messages
CREATE TABLE IF NOT EXISTS enquiry_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enquiry_id INT NOT NULL,
  mobile VARCHAR(50) NOT NULL,
  direction ENUM('incoming', 'outgoing') NOT NULL,
  message_text TEXT NOT NULL,
  sent_by VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(mobile),
  FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Follow-up history for manual enquiries
CREATE TABLE IF NOT EXISTS enquiry_follow_ups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enquiry_id INT NOT NULL,
  contacted_at DATETIME NOT NULL,
  contact_method VARCHAR(30) NOT NULL,
  discussion_note TEXT NOT NULL,
  quoted_amount DECIMAL(12,2) DEFAULT NULL,
  next_follow_up_at DATETIME DEFAULT NULL,
  result VARCHAR(50) NOT NULL,
  created_by VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_enquiry_follow_ups_enquiry_id (enquiry_id),
  INDEX idx_enquiry_follow_ups_next_date (next_follow_up_at),
  FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
