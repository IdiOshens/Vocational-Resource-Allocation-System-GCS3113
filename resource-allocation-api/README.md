# Resource Allocation API

PHP backend for the Vocational School Resource Allocation System.

## Technologies

- PHP 8+
- MySQL / MySQLi (Object-Oriented)
- XAMPP (Apache + MySQL)
- phpMyAdmin

## Project Structure

resource-allocation-api/
├── config/
│ └── database.php # Database credentials
├── functions.php # Business logic
├── apis.php # REST API endpoints
├── create_admin.php # One-time admin creation script
└── .gitignore

## Setup Instructions

1. Clone the repository into `C:\xampp\htdocs\`
2. Start XAMPP (Apache + MySQL)
3. Import the database SQL file in phpMyAdmin
4. Run `create_admin.php` once to create the first admin
5. Delete or protect `create_admin.php` afterwards
6. Test endpoints via Postman or the mobile app

## API Endpoints (Coming Soon)

- POST `/apis.php?action=login`
- POST `/apis.php?action=register`
- GET `/apis.php?action=classrooms`
- etc.

## Author

[Idi mbuga] - Gulu University, Computer Science Year 3
