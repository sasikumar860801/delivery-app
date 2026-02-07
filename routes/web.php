<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Vendor\VendorController;
use App\Http\Controllers\Delivery\DeliveryPartnerController;
use App\Http\Controllers\Customer\CustomerController;

Route::prefix('admin')->group(function () {
    // Public routes
    Route::post('/login', [AuthController::class, 'login']);

    // Protected routes (Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', [DashboardController::class, 'index']);
        
        // Admin Management Routes
        Route::prefix('manage')->group(function () {
            // Categories
            Route::get('/categories', [AdminController::class, 'getCategories']);
            Route::post('/categories', [AdminController::class, 'createCategory']);
            Route::put('/categories/{id}', [AdminController::class, 'updateCategory']);
            Route::delete('/categories/{id}', [AdminController::class, 'deleteCategory']);
            
            // Vendors
            Route::get('/vendors', [AdminController::class, 'getVendors']);
            Route::post('/vendors', [AdminController::class, 'createVendor']);
            Route::post('/vendors/{id}', [AdminController::class, 'updateVendor']);
            Route::post('/vendors/{id}/status', [AdminController::class, 'updateVendorStatus']);
            
            // Customers
            Route::get('/customers', [AdminController::class, 'getCustomers']);
            Route::post('/customers/{id}/status', [AdminController::class, 'updateCustomerStatus']);
            
            // Delivery Partners
            Route::get('/delivery-partners', [AdminController::class, 'getDeliveryPartners']);
            Route::post('/delivery-partners/verify/{id}', [AdminController::class, 'verifyDeliveryPartner']);
            Route::post('/delivery-partners/{id}/status', [AdminController::class, 'updateDeliveryPartnerStatus']);
            
            // Orders
            Route::get('/orders', [AdminController::class, 'getOrders']);
            Route::get('/orders/{id}', [AdminController::class, 'getOrderDetails']);
            Route::post('/orders/{id}/status', [AdminController::class, 'updateOrderStatus']);
            
            // Support Tickets
            Route::get('/support-tickets', [AdminController::class, 'getSupportTickets']);
            Route::get('/support-tickets/{id}', [AdminController::class, 'getTicketDetails']);
            Route::post('/support-tickets/{id}/reply', [AdminController::class, 'replyToTicket']);
            Route::put('/support-tickets/{id}/status', [AdminController::class, 'updateTicketStatus']);
        });
    });
});


Route::prefix('vendor')->group(function () {
    // Public routes
    Route::post('/send-otp', [VendorController::class, 'sendOtp']);
    Route::post('/verify-otp', [VendorController::class, 'verifyOtp']);
    Route::post('/vendor_login', [AuthController::class, 'vendor_login']);


    // Protected routes (Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [VendorController::class, 'logout']);
        Route::get('/dashboard', [VendorController::class, 'dashboard']);
        // Profile
        Route::get('/profile', [VendorController::class, 'getProfile']);
        Route::post('/profile', [VendorController::class, 'updateProfile']);
        
        // Products
        Route::get('/products', [VendorController::class, 'getProducts']);
        Route::post('/products', [VendorController::class, 'addProduct']);
        Route::post('/products/{id}', [VendorController::class, 'updateProduct']);
        Route::post('/products/{id}/stock', [VendorController::class, 'updateStock']);
        Route::delete('/products/{id}', [VendorController::class, 'deleteProduct']);
        
        // Orders
        Route::get('/orders', [VendorController::class, 'getOrders']);
        Route::get('/orders/{id}', [VendorController::class, 'getOrderDetails']);
        Route::post('/orders/{id}/status', [VendorController::class, 'updateOrderStatus']);
        
        // Earnings
        Route::get('/earnings', [VendorController::class, 'getEarnings']);
        
        // Notifications
        Route::get('/notifications', [VendorController::class, 'getNotifications']);
        Route::post('/notifications/{id}/read', [VendorController::class, 'markNotificationRead']);
    });
});

Route::prefix('delivery')->group(function () {
    // Public routes
    Route::post('/send-otp', [DeliveryPartnerController::class, 'sendOtp']);
    Route::post('/verifyOtp_delivery_partner', [AuthController::class, 'verifyOtp_delivery_partner']);
    
    // Protected routes (Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [DeliveryPartnerController::class, 'logout']);
        Route::get('/dashboard', [DeliveryPartnerController::class, 'dashboard']);
        
        // Profile
        Route::get('/profile', [DeliveryPartnerController::class, 'getProfile']);
        Route::post('/profile', [DeliveryPartnerController::class, 'updateProfile']);
        
        // Availability & Location
        Route::put('/availability', [DeliveryPartnerController::class, 'updateAvailability']);
        Route::post('/location', [DeliveryPartnerController::class, 'updateLocation']);
        
        // Tasks
        Route::get('/tasks/available', [DeliveryPartnerController::class, 'getAvailableTasks']);
        Route::get('/tasks', [DeliveryPartnerController::class, 'getMyTasks']);
        Route::get('/tasks/{id}', [DeliveryPartnerController::class, 'getTaskDetails']);
        Route::post('/tasks/{id}/accept', [DeliveryPartnerController::class, 'acceptTask']);
        Route::post('/tasks/{id}/status', [DeliveryPartnerController::class, 'updateTaskStatus']);
        
        // Earnings
        Route::get('/earnings', [DeliveryPartnerController::class, 'getEarnings']);
        Route::get('/earnings/summary', [DeliveryPartnerController::class, 'getEarningsSummary']);
        
        // Notifications
        Route::get('/notifications', [DeliveryPartnerController::class, 'getNotifications']);
    });
});


Route::prefix('customer')->group(function () {
    // Public routes
    Route::post('/send-otp', [CustomerController::class, 'sendOtp']);
    Route::post('/verify-otp', [CustomerController::class, 'verifyOtp']);
        Route::post('/verifyOtp_customer', [AuthController::class, 'verifyOtp_customer']);

    // Protected routes (Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [CustomerController::class, 'logout']);
        
        // Profile
        Route::get('/profile', [CustomerController::class, 'getProfile']);
        Route::post('/profile', [CustomerController::class, 'updateProfile']);
        
        // Home & Products
        Route::get('/home', [CustomerController::class, 'getHomeData']);
        Route::get('/categories', [CustomerController::class, 'getCategories']);
        Route::get('/categories/{id}/products', [CustomerController::class, 'getProductsByCategory']);
        Route::get('/products/search', [CustomerController::class, 'searchProducts']);
        Route::get('/products/{id}', [CustomerController::class, 'getProductDetails']);
        
        // Cart
        Route::get('/cart', [CustomerController::class, 'getCart']);
        Route::post('/cart', [CustomerController::class, 'addToCart']);
        Route::post('/cart/{id}', [CustomerController::class, 'updateCartItem']);
        Route::delete('/cart/{id}', [CustomerController::class, 'removeFromCart']);
        Route::delete('/cart', [CustomerController::class, 'clearCart']);
        
        // Wishlist
        Route::get('/wishlist', [CustomerController::class, 'getWishlist']);
        Route::post('/wishlist', [CustomerController::class, 'addToWishlist']);
        Route::delete('/wishlist/{productId}', [CustomerController::class, 'removeFromWishlist']);
        
        // Addresses
        Route::get('/addresses', [CustomerController::class, 'getAddresses']);
        Route::post('/addresses', [CustomerController::class, 'addAddress']);
        Route::post('/addresses/{id}', [CustomerController::class, 'updateAddress']);
        Route::delete('/addresses/{id}', [CustomerController::class, 'deleteAddress']);
        Route::post('/addresses/{id}/default', [CustomerController::class, 'setDefaultAddress']);
        
        // Orders
        Route::get('/orders', [CustomerController::class, 'getOrders']);
        Route::get('/orders/{id}', [CustomerController::class, 'getOrderDetails']);
        Route::post('/orders', [CustomerController::class, 'placeOrder']);
        Route::post('/orders/{id}/cancel', [CustomerController::class, 'cancelOrder']);
        
        // Reviews
        Route::post('/reviews', [CustomerController::class, 'submitReview']);
        
        // Notifications
        Route::get('/notifications', [CustomerController::class, 'getNotifications']);
        Route::post('/notifications/{id}/read', [CustomerController::class, 'markNotificationRead']);
    });
});
