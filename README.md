<<<<<<< HEAD
# Spa Ecommerce System

A complete, beginner-friendly CRUD Booking and E-commerce System built with procedural PHP, MySQL, HTML, CSS, and Vanilla JavaScript.

## Features

### Admin Panel
- **Admin Authentication**: Secure login with password hashing
- **Dashboard**: Real-time statistics and analytics
- **Services Management**: Add, edit, delete spa services with image uploads
- **Products Management**: Manage spa products with inventory tracking
- **Users Management**: View and manage registered users
- **Appointments Management**: Approve, decline, or mark appointments as completed
- **Orders Management**: View all orders and order details

### User Features
- **User Registration & Login**: Secure account creation and authentication
- **Service Browsing**: View all available spa services with details
- **Product Browsing**: Browse spa products with stock information
- **Shopping Cart**: Add products to cart, update quantities, remove items
- **Checkout System**: Three checkout scenarios:
  - Direct product checkout
  - Cart checkout with multiple items
  - Service booking with appointment scheduling
- **User Profile**: View and update personal information
- **Appointments**: View all bookings with status tracking (pending, approved, declined, completed)

## Project Structure

```
spa_ecommerce_system/
├── config.php                 # Database configuration
├── database.sql              # SQL schema
├── README.md                 # This file
├── assets/
│   └── style.css            # Main stylesheet
├── admin/
│   ├── index.php            # Admin login & dashboard
│   ├── services.php         # Services CRUD
│   ├── products.php         # Products CRUD
│   ├── users.php            # Users management
│   ├── appointments.php     # Appointments management
│   └── orders.php           # Orders management
├── user/
│   ├── auth.php             # User registration & login
│   ├── index.php            # Homepage with services & products
│   ├── cart.php             # Shopping cart
│   ├── checkout.php         # Checkout system
│   ├── profile.php          # User profile
│   └── appointments.php     # User appointments
└── uploads/
    ├── services/            # Service images
    └── products/            # Product images
```

## Installation & Setup

### Prerequisites
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache, Nginx, etc.)

### Step 1: Database Setup

1. Open your MySQL client (phpMyAdmin, MySQL Workbench, or command line)
2. Create a new database:
   ```sql
   CREATE DATABASE spa_ecommerce_db;
   ```
3. Import the SQL schema:
   ```sql
   USE spa_ecommerce_db;
   SOURCE database.sql;
   ```

### Step 2: Configure Database Connection

1. Open `config.php`
2. Update the database credentials:
   ```php
   define("DB_USERNAME", "your_mysql_username");
   define("DB_PASSWORD", "your_mysql_password");
   define("DB_NAME", "spa_ecommerce_db");
   ```
3. Update the BASE_URL if your project is in a subdirectory:
   ```php
   define("BASE_URL", "http://localhost/spa_ecommerce_system/");
   ```

### Step 3: Create Upload Directories

Ensure these directories exist and are writable:
- `uploads/services/`
- `uploads/products/`

Set permissions (Linux/Mac):
```bash
chmod 755 uploads/
chmod 755 uploads/services/
chmod 755 uploads/products/
```

### Step 4: Access the Application

- **Admin Panel**: `http://localhost/spa_ecommerce_system/admin/`
  - Default credentials: `admin` / `admin123`
- **User Frontend**: `http://localhost/spa_ecommerce_system/user/`

## Default Admin Credentials

- **Username**: `admin`
- **Password**: `admin123`

⚠️ **Important**: Change these credentials after first login!

## Database Schema

### Tables

#### `admin`
- `id` (INT, Primary Key)
- `username` (VARCHAR)
- `password` (VARCHAR, hashed)

#### `users`
- `id` (INT, Primary Key)
- `username` (VARCHAR, Unique)
- `password` (VARCHAR, hashed)
- `email` (VARCHAR, Unique)
- `full_name` (VARCHAR)
- `phone` (VARCHAR)
- `address` (TEXT)
- `created_at` (TIMESTAMP)

#### `services`
- `id` (INT, Primary Key)
- `name` (VARCHAR)
- `image` (VARCHAR)
- `description` (TEXT)
- `price` (DECIMAL)
- `session_time` (INT, in minutes)
- `created_at` (TIMESTAMP)

#### `products`
- `id` (INT, Primary Key)
- `name` (VARCHAR)
- `image` (VARCHAR)
- `description` (TEXT)
- `price` (DECIMAL)
- `stock` (INT)
- `created_at` (TIMESTAMP)

#### `orders`
- `id` (INT, Primary Key)
- `customer_name` (VARCHAR)
- `email` (VARCHAR)
- `phone` (VARCHAR)
- `address` (TEXT)
- `booking_date` (DATETIME)
- `total_amount` (DECIMAL)
- `created_at` (TIMESTAMP)

#### `order_items`
- `id` (INT, Primary Key)
- `order_id` (INT, Foreign Key)
- `product_id` (INT, Foreign Key, Nullable)
- `service_id` (INT, Foreign Key, Nullable)
- `quantity` (INT)
- `price` (DECIMAL)
- `subtotal` (DECIMAL)

#### `appointments`
- `id` (INT, Primary Key)
- `user_id` (INT, Foreign Key)
- `service_id` (INT, Foreign Key)
- `order_item_id` (INT, Foreign Key, Nullable)
- `appointment_date` (DATETIME)
- `status` (ENUM: pending, approved, declined, completed)
- `created_at` (TIMESTAMP)

## Features Explained

### Admin Panel

**Services Management**
- Add new spa services with image upload
- Edit existing services
- Delete services
- View all services in a table format

**Products Management**
- Add new products with stock quantity
- Edit product information
- Delete products
- Track inventory levels

**Users Management**
- View all registered users
- View user details and appointment history
- Delete user accounts

**Appointments Management**
- View all user appointments
- Approve pending appointments
- Decline appointments
- Mark appointments as completed
- Filter by status

**Orders Management**
- View all orders with customer information
- View detailed order information
- Track revenue

### User Features

**Service Booking**
- Browse all available services
- View service details in modal popup
- Book services directly (redirects to checkout)
- Schedule appointment date and time

**Product Shopping**
- Browse all available products
- View product details in modal
- Add products to cart
- Direct checkout option
- Inventory tracking (out of stock display)

**Shopping Cart**
- Add multiple products
- Update quantities
- Remove items
- Select items for checkout
- View cart totals

**Checkout**
- Three checkout scenarios supported
- Fill in billing information
- Schedule appointment date (for services)
- Inventory reduction on purchase
- Order confirmation

**User Profile**
- View personal information
- Update profile details
- Change password
- Logout

**Appointments**
- View all bookings
- Filter by status
- Separate upcoming and history sections
- View appointment details

## Design Features

### Color Palette
- **Primary**: Espresso Brown (#3B2A1A), Burnt Orange (#C96A2C), Rust Orange (#A94F1D)
- **Secondary**: Warm Beige (#F4E7D3), Cream (#FAF3E8), Soft Sand (#EAD8C0)
- **Accent**: Soft Gold (#C8A46B)

### Responsive Design
- Desktop (1200px+)
- Tablet (768px - 1199px)
- Mobile (480px - 767px)

### UI Components
- Sticky navigation bar
- Hero section
- Card-based layout with hover effects
- Modal popups for details
- Responsive tables
- Flash messages for feedback

## Security Features

- **Password Hashing**: Uses PHP's `password_hash()` and `password_verify()`
- **Input Sanitization**: All user inputs are sanitized
- **Prepared Statements**: SQL injection prevention
- **Session Management**: Secure session handling
- **File Upload Validation**: Type and size validation for images

## File Upload Handling

- Supported formats: JPEG, PNG, GIF, WebP
- Maximum file size: 5MB
- Files are renamed with timestamp to prevent conflicts
- Old files are deleted when updated

## Inventory Management

- Stock is automatically reduced when products are purchased
- Products display "Out of Stock" when quantity reaches 0
- Stock levels are tracked in real-time

## Appointment System

**Status Flow**:
1. **Pending**: Initial status when booking is created
2. **Approved**: Admin approves the appointment
3. **Declined**: Admin declines the appointment
4. **Completed**: Appointment has been completed

## Troubleshooting

### Database Connection Error
- Check if MySQL is running
- Verify credentials in `config.php`
- Ensure database exists

### Upload Directory Issues
- Check if `uploads/` directory exists
- Verify write permissions (chmod 755)
- Check file size limits in PHP configuration

### Session Issues
- Ensure `session_start()` is called
- Check PHP session configuration
- Clear browser cookies if needed

## Future Enhancements

- Payment gateway integration
- Email notifications
- SMS reminders
- Review and rating system
- Loyalty program
- Advanced analytics
- Multi-language support
- Dark mode

## Support

For issues or questions, please refer to the code comments throughout the application.

## License

This project is provided as-is for educational purposes.

---

**Created**: 2024
**Version**: 1.0
**Built with**: PHP, MySQL, HTML, CSS, Vanilla JavaScript
=======
# Capstone_Recovery-system
>>>>>>> 47cc516a47ece95668abb96d60f6128e966e6279
