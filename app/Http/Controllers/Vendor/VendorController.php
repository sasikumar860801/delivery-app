<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class VendorController extends Controller
{
    // ==================== AUTHENTICATION ====================
    
    /**
     * Send OTP for login/register
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string|max:20',
            'email' => 'nullable|email'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $mobile = $request->mobile;
            $email = $request->email;
            
            // Generate OTP
            $otp = rand(100000, 999999);
            $otpExpires = now()->addMinutes(10);
            
            // Check if vendor exists
            $vendorExists = DB::table('vendors')
                ->where('phone', $mobile)
                ->orWhere('email', $email)
                ->first();
            
            if ($vendorExists) {
                // Existing vendor - login
                $vendorId = $vendorExists->id;
                
                // Update or create auth record
                DB::table('vendor_auth')->updateOrInsert(
                    ['vendor_id' => $vendorId],
                    [
                        'mobile' => $mobile,
                        'email' => $email,
                        'otp' => $otp,
                        'otp_expires_at' => $otpExpires,
                        'updated_at' => now()
                    ]
                );
                
                return response()->json([
                    'success' => true,
                    'message' => 'OTP sent to mobile',
                    'is_existing' => true,
                    'otp' => $otp // Remove this in production - only for testing
                ]);
            } else {
                // New vendor - registration
                $tempVendorId = Str::random(20);
                
                // Store in temp session or temp table
                DB::table('temp_vendor_auth')->updateOrInsert(
                    ['mobile' => $mobile],
                    [
                        'email' => $email,
                        'otp' => $otp,
                        'otp_expires_at' => $otpExpires,
                        'temp_data' => json_encode([
                            'name' => $request->name,
                            'business_name' => $request->business_name
                        ]),
                        'created_at' => now()
                    ]
                );
                
                return response()->json([
                    'success' => true,
                    'message' => 'OTP sent to mobile',
                    'is_existing' => false,
                    'otp' => $otp // Remove this in production
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Verify OTP and login/register
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string',
            'otp' => 'required|string|size:6'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $mobile = $request->mobile;
            $otp = $request->otp;
            
            // Check for existing vendor
            $vendorAuth = DB::table('vendor_auth')
                ->where('mobile', $mobile)
                ->first();
            
            if ($vendorAuth) {
                // Existing vendor login
                if ($vendorAuth->otp !== $otp) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid OTP'
                    ], 400);
                }
                
                if (now()->gt($vendorAuth->otp_expires_at)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'OTP expired'
                    ], 400);
                }
                
                // Get vendor details
                $vendor = DB::table('vendors')->find($vendorAuth->vendor_id);
                
                if (!$vendor) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vendor not found'
                    ], 404);
                }
                
                // Check if vendor is active
                if ($vendor->status !== 'active') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Your account is ' . $vendor->status
                    ], 403);
                }
                
                $raw_token = Str::random(40);
                // Create Sanctum token
                $token = DB::table('personal_access_tokens')->insertGetId([
                    'tokenable_type' => 'App\Models\Vendor',
                    'tokenable_id' => $vendor->id,
                    'name' => 'vendor-api-token',
                    'token' => hash('sha256', $raw_token),
                    'abilities' => '["*"]',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                // Clear OTP
                DB::table('vendor_auth')
                    ->where('id', $vendorAuth->id)
                    ->update([
                        'otp' => null,
                        'otp_expires_at' => null,
                        'phone_verified_at' => now()
                    ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Login successful',
                    'token' => $raw_token,
                    'vendor' => [
                        'id' => $vendor->id,
                        'name' => $vendor->name,
                        'email' => $vendor->email,
                        'phone' => $vendor->phone,
                        'business_name' => $vendor->business_name,
                        'status' => $vendor->status
                    ]
                ]);
            } else {
                // New vendor registration
                $tempAuth = DB::table('temp_vendor_auth')
                    ->where('mobile', $mobile)
                    ->first();
                
                if (!$tempAuth) {
                    return response()->json([
                        'success' => false,
                        'message' => 'OTP not requested'
                    ], 400);
                }
                
                if ($tempAuth->otp !== $otp) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid OTP'
                    ], 400);
                }
                
                if (now()->gt($tempAuth->otp_expires_at)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'OTP expired'
                    ], 400);
                }
                
                // Parse temp data
                $tempData = json_decode($tempAuth->temp_data, true);
                
                // Create new vendor
                $vendorId = DB::table('vendors')->insertGetId([
                    'name' => $tempData['name'] ?? 'Vendor',
                    'email' => $tempAuth->email,
                    'phone' => $mobile,
                    'business_name' => $tempData['business_name'] ?? 'Business',
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                // Create auth record
                DB::table('vendor_auth')->insert([
                    'vendor_id' => $vendorId,
                    'mobile' => $mobile,
                    'email' => $tempAuth->email,
                    'phone_verified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                // Clear temp data
                DB::table('temp_vendor_auth')->where('id', $tempAuth->id)->delete();
                
                $raw_token = Str::random(40);
                // Create token
                $token = DB::table('personal_access_tokens')->insertGetId([
                    'tokenable_type' => 'vendor',
                    'tokenable_id' => $vendorId,
                    'name' => 'vendor-api-token',
                    'token' => hash('sha256', $raw_token),
                    'abilities' => '["*"]',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Registration successful. Account pending approval.',
                    'token' => $raw_token,
                    'vendor' => [
                        'id' => $vendorId,
                        'name' => $tempData['name'] ?? 'Vendor',
                        'email' => $tempAuth->email,
                        'phone' => $mobile,
                        'business_name' => $tempData['business_name'] ?? 'Business',
                        'status' => 'pending'
                    ]
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify OTP',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Vendor logout
     */
    public function logout(Request $request)
    {
        try {
            $tokenId = $request->user()->currentAccessToken()->id;
            
            DB::table('personal_access_tokens')->where('id', $tokenId)->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to logout',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== DASHBOARD ====================
    
    /**
     * Get vendor dashboard stats
     */
    public function dashboard(Request $request)
    {
        try {
            $vendorId = $request->user()->id;
            
            // Today's stats
            $today = now()->format('Y-m-d');
            
            $todayOrders = DB::table('vendor_orders')
                ->where('vendor_id', $vendorId)
                ->whereDate('created_at', $today)
                ->count();
            
            $todayEarnings = DB::table('vendor_earnings')
                ->where('vendor_id', $vendorId)
                ->whereDate('created_at', $today)
                ->where('status', 'paid')
                ->sum('net_amount');
            
            // Total stats
            $totalOrders = DB::table('vendor_orders')
                ->where('vendor_id', $vendorId)
                ->count();
            
            $totalEarnings = DB::table('vendor_earnings')
                ->where('vendor_id', $vendorId)
                ->where('status', 'paid')
                ->sum('net_amount');
            
            // Pending orders
            $pendingOrders = DB::table('vendor_orders')
                ->where('vendor_id', $vendorId)
                ->whereIn('status', ['pending', 'accepted', 'preparing'])
                ->count();
            
            // Recent orders
            $recentOrders = DB::table('vendor_orders')
                ->join('orders', 'vendor_orders.order_id', '=', 'orders.id')
                ->join('customers', 'orders.customer_id', '=', 'customers.id')
                ->where('vendor_orders.vendor_id', $vendorId)
                ->select(
                    'vendor_orders.*',
                    'orders.order_number',
                    'orders.final_amount',
                    'customers.name as customer_name'
                )
                ->orderBy('vendor_orders.created_at', 'desc')
                ->limit(5)
                ->get();
            
            // ✅ UPDATED: Low stock products (now from products table)
            $lowStockProducts = DB::table('products')
                ->where('vendor_id', $vendorId)
                ->where('stock_quantity', '<', 10)
                ->where('stock_quantity', '>', 0)
                ->where('is_active', true)
                ->count();
            
            // ✅ UPDATED: Out of stock products (now from products table)
            $outOfStockProducts = DB::table('products')
                ->where('vendor_id', $vendorId)
                ->where('stock_quantity', 0)
                ->where('is_active', true)
                ->count();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'today_orders' => $todayOrders,
                    'today_earnings' => (float) $todayEarnings,
                    'total_orders' => $totalOrders,
                    'total_earnings' => (float) $totalEarnings,
                    'pending_orders' => $pendingOrders,
                    'low_stock_products' => $lowStockProducts,
                    'out_of_stock_products' => $outOfStockProducts,
                    'recent_orders' => $recentOrders
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== PRODUCT MANAGEMENT ====================
    
    /**
     * Get vendor products
     */
    public function getProducts(Request $request)
    {
        try {
            $vendorId = $request->user()->id;
            
            // ✅ UPDATED: Query products table instead of vendor_products
            $query = DB::table('products')
                ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
                ->where('products.vendor_id', $vendorId) // Filter by vendor_id
                ->select(
                    'products.*',
                    'categories.name as category_name'
                );
            
            // Filter by category
            if ($request->has('category_id')) {
                $query->where('products.category_id', $request->category_id);
            }
            
            // Filter by status
            if ($request->has('is_active')) {
                $query->where('products.is_active', $request->is_active);
            }
            
            // Filter by stock
            if ($request->has('stock_status')) {
                if ($request->stock_status === 'low') {
                    $query->where('stock_quantity', '<', 10)->where('stock_quantity', '>', 0);
                } elseif ($request->stock_status === 'out') {
                    $query->where('stock_quantity', 0);
                }
            }
            
            // Search
            if ($request->has('search')) {
                $query->where(function($q) use ($request) {
                    $q->where('products.name', 'like', "%{$request->search}%")
                      ->orWhere('products.sku', 'like', "%{$request->search}%");
                });
            }
            
            $products = $query->orderBy('products.created_at', 'desc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $products
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Add new product
     */
    public function addProduct(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'discounted_price' => 'nullable|numeric|min:0',
            'sku' => 'nullable|string|max:100',
            'stock_quantity' => 'required|integer|min:0',
            'min_order_quantity' => 'nullable|integer|min:1',
            'max_order_quantity' => 'nullable|integer|min:1',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $vendorId = $request->user()->id;

            // Handle image upload
            $imagePaths = [];

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('vendor_products', 'public');
                    $imagePaths[] = $path;
                }
            }

            // ✅ UPDATED: Insert into products table (main table that customers see)
            $productId = DB::table('products')->insertGetId([
                'vendor_id' => $vendorId, // This is the KEY field
                'category_id' => $request->category_id,
                'name' => $request->name,
                'slug' => Str::slug($request->name) . '-' . time(),
                'description' => $request->description,
                'price' => $request->price,
                'discounted_price' => $request->discounted_price,
                'sku' => $request->sku,
                'stock_quantity' => $request->stock_quantity,
                'min_order_quantity' => $request->min_order_quantity ?? 1,
                'max_order_quantity' => $request->max_order_quantity,
                'images' => !empty($imagePaths) ? json_encode($imagePaths) : null,
                'is_active' => $request->is_active ?? true,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $product = DB::table('products')->find($productId);

            // Append full image URLs
            if ($product && $product->images) {
                $images = json_decode($product->images, true);
                $product->image_urls = array_map(function ($img) {
                    return asset('storage/' . $img);
                }, $images);
            }

            return response()->json([
                'success' => true,
                'message' => 'Product added successfully',
                'data' => $product
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add product',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update product
     */
    public function updateProduct(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'price' => 'numeric|min:0',
            'discounted_price' => 'nullable|numeric|min:0',
            'sku' => 'nullable|string|max:100',
            'stock_quantity' => 'integer|min:0',
            'min_order_quantity' => 'nullable|integer|min:1',
            'max_order_quantity' => 'nullable|integer|min:1',
            'images' => 'nullable|array',
            'is_active' => 'boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $vendorId = $request->user()->id;
            
            // ✅ UPDATED: Check if product belongs to vendor in products table
            $product = DB::table('products')
                ->where('id', $id)
                ->where('vendor_id', $vendorId)
                ->first();
            
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found or access denied'
                ], 404);
            }
            
            $updateData = [];
            if ($request->has('name')) {
                $updateData['name'] = $request->name;
                $updateData['slug'] = Str::slug($request->name) . '-' . time();
            }
            if ($request->has('category_id')) $updateData['category_id'] = $request->category_id;
            if ($request->has('description')) $updateData['description'] = $request->description;
            if ($request->has('price')) $updateData['price'] = $request->price;
            if ($request->has('discounted_price')) $updateData['discounted_price'] = $request->discounted_price;
            if ($request->has('sku')) $updateData['sku'] = $request->sku;
            if ($request->has('stock_quantity')) $updateData['stock_quantity'] = $request->stock_quantity;
            if ($request->has('min_order_quantity')) $updateData['min_order_quantity'] = $request->min_order_quantity;
            if ($request->has('max_order_quantity')) $updateData['max_order_quantity'] = $request->max_order_quantity;
            if ($request->has('images')) $updateData['images'] = json_encode($request->images);
            if ($request->has('is_active')) $updateData['is_active'] = $request->is_active;
            
            $updateData['updated_at'] = now();
            
            // ✅ UPDATED: Update in products table
            DB::table('products')
                ->where('id', $id)
                ->where('vendor_id', $vendorId)
                ->update($updateData);
            
            $updatedProduct = DB::table('products')->find($id);
            
            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $updatedProduct
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update stock availability
     */
    public function updateStock(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'stock_quantity' => 'required|integer|min:0'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $vendorId = $request->user()->id;
            
            // ✅ UPDATED: Check if product belongs to vendor in products table
            $product = DB::table('products')
                ->where('id', $id)
                ->where('vendor_id', $vendorId)
                ->first();
            
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }
            
            // ✅ UPDATED: Update stock in products table
            DB::table('products')
                ->where('id', $id)
                ->where('vendor_id', $vendorId)
                ->update([
                    'stock_quantity' => $request->stock_quantity,
                    'updated_at' => now()
                ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Stock updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Delete product
     */
    public function deleteProduct(Request $request, $id)
    {
        try {
            $vendorId = $request->user()->id;
            
            // ✅ UPDATED: Check if product belongs to vendor in products table
            $product = DB::table('products')
                ->where('id', $id)
                ->where('vendor_id', $vendorId)
                ->first();
            
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }
            
            // Check if product has active orders
            $hasOrders = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('order_items.product_id', $id)
                ->whereIn('orders.order_status', ['pending', 'confirmed', 'processing'])
                ->exists();
            
            if ($hasOrders) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete product with active orders'
                ], 400);
            }
            
            // ✅ UPDATED: Delete from products table
            DB::table('products')
                ->where('id', $id)
                ->where('vendor_id', $vendorId)
                ->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

        // ==================== ORDER MANAGEMENT ====================
    
    /**
     * Get vendor orders
     */
    public function getOrders(Request $request)
    {
        try {
            $vendorId = $request->user()->id;
            
            $query = DB::table('vendor_orders')
                ->join('orders', 'vendor_orders.order_id', '=', 'orders.id')
                ->join('customers', 'orders.customer_id', '=', 'customers.id')
                ->where('vendor_orders.vendor_id', $vendorId)
                ->select(
                    'vendor_orders.*',
                    'orders.order_number',
                    'orders.final_amount',
                    'orders.payment_status',
                    'orders.delivery_address',
                    'orders.estimated_delivery_time',
                    'customers.name as customer_name',
                    'customers.phone as customer_phone'
                );
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('vendor_orders.status', $request->status);
            }
            
            // Filter by date
            if ($request->has('date')) {
                $query->whereDate('vendor_orders.created_at', $request->date);
            }
            
            // Filter by search
            if ($request->has('search')) {
                $query->where(function($q) use ($request) {
                    $q->where('orders.order_number', 'like', "%{$request->search}%")
                      ->orWhere('customers.name', 'like', "%{$request->search}%")
                      ->orWhere('customers.phone', 'like', "%{$request->search}%");
                });
            }
            
            $orders = $query->orderBy('vendor_orders.created_at', 'desc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $orders
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get order details
     */
    public function getOrderDetails(Request $request, $id)
    {
        try {
            $vendorId = $request->user()->id;
            
            // Check if order belongs to vendor
            $vendorOrder = DB::table('vendor_orders')
                ->where('id', $id)
                ->where('vendor_id', $vendorId)
                ->first();
            
            if (!$vendorOrder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }
            
            // Get order details
            $order = DB::table('orders')
                ->join('customers', 'orders.customer_id', '=', 'customers.id')
                ->where('orders.id', $vendorOrder->order_id)
                ->select(
                    'orders.*',
                    'customers.name as customer_name',
                    'customers.email as customer_email',
                    'customers.phone as customer_phone'
                )
                ->first();
            
            // ✅ UPDATED: Get order items from products table (not vendor_products)
            $orderItems = DB::table('order_items')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->where('order_items.order_id', $vendorOrder->order_id)
                ->where('products.vendor_id', $vendorId) // Filter by vendor_id
                ->select(
                    'order_items.*',
                    'products.name as product_name',
                    'products.images'
                )
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'order' => $order,
                    'vendor_order' => $vendorOrder,
                    'items' => $orderItems
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update order status (prepare/ready)
     */
    public function updateOrderStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:accepted,preparing,ready,cancelled',
            'preparation_time' => 'nullable|integer|min:1',
            'notes' => 'nullable|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $vendorId = $request->user()->id;
            
            // Check if order belongs to vendor
            $vendorOrder = DB::table('vendor_orders')
                ->where('id', $id)
                ->where('vendor_id', $vendorId)
                ->first();
            
            if (!$vendorOrder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }
            
            $updateData = [
                'status' => $request->status,
                'updated_at' => now()
            ];
            
            // Set timestamps based on status
            if ($request->status === 'accepted') {
                $updateData['accepted_at'] = now();
                $updateData['preparation_time'] = $request->preparation_time;
            } elseif ($request->status === 'preparing') {
                $updateData['prepared_at'] = now();
            } elseif ($request->status === 'ready') {
                $updateData['ready_at'] = now();
            }
            
            if ($request->has('notes')) {
                $updateData['notes'] = $request->notes;
            }
            
            DB::table('vendor_orders')
                ->where('id', $id)
                ->where('vendor_id', $vendorId)
                ->update($updateData);
            
            // Update main order status if ready
            if ($request->status === 'ready') {
                DB::table('orders')
                    ->where('id', $vendorOrder->order_id)
                    ->update([
                        'order_status' => 'ready',
                        'updated_at' => now()
                    ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => "Order status updated to {$request->status}"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== EARNINGS ====================
    
    /**
     * Get vendor earnings
     */
    public function getEarnings(Request $request)
    {
        try {
            $vendorId = $request->user()->id;
            
            $query = DB::table('vendor_earnings')
                ->join('orders', 'vendor_earnings.order_id', '=', 'orders.id')
                ->where('vendor_earnings.vendor_id', $vendorId)
                ->select(
                    'vendor_earnings.*',
                    'orders.order_number',
                    'orders.created_at as order_date'
                );
            
            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('vendor_earnings.created_at', [
                    $request->start_date,
                    $request->end_date
                ]);
            }
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('vendor_earnings.status', $request->status);
            }
            
            $earnings = $query->orderBy('vendor_earnings.created_at', 'desc')->get();
            
            // Summary
            $totalEarnings = $earnings->where('status', 'paid')->sum('net_amount');
            $pendingEarnings = $earnings->where('status', 'pending')->sum('net_amount');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'earnings' => $earnings,
                    'summary' => [
                        'total' => (float) $totalEarnings,
                        'pending' => (float) $pendingEarnings
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch earnings',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== PROFILE ====================
    
    /**
     * Get vendor profile
     */
    public function getProfile(Request $request)
    {
        try {
            $vendorId = $request->user()->id;
            
            $vendor = DB::table('vendors')->find($vendorId);
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => $vendor
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update vendor profile
     */
    public function updateProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'email' => 'email|unique:vendors,email,' . $request->user()->id,
            'business_name' => 'string|max:255',
            'business_address' => 'nullable|string',
            'logo' => 'nullable|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $vendorId = $request->user()->id;
            
            $updateData = [];
            if ($request->has('name')) $updateData['name'] = $request->name;
            if ($request->has('email')) $updateData['email'] = $request->email;
            if ($request->has('business_name')) $updateData['business_name'] = $request->business_name;
            if ($request->has('business_address')) $updateData['business_address'] = $request->business_address;
            if ($request->has('logo')) $updateData['logo'] = $request->logo;
            
            $updateData['updated_at'] = now();
            
            DB::table('vendors')->where('id', $vendorId)->update($updateData);
            
            $updatedVendor = DB::table('vendors')->find($vendorId);
            
            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $updatedVendor
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== NOTIFICATIONS ====================
    
    /**
     * Get vendor notifications
     */
    public function getNotifications(Request $request)
    {
        try {
            $vendorId = $request->user()->id;
            
            $query = DB::table('vendor_notifications')
                ->where('vendor_id', $vendorId);
            
            // Filter by read status
            if ($request->has('is_read')) {
                $query->where('is_read', $request->is_read);
            }
            
            $notifications = $query->orderBy('created_at', 'desc')->get();
            
            // Mark as read if requested
            if ($request->has('mark_read') && $request->mark_read) {
                DB::table('vendor_notifications')
                    ->where('vendor_id', $vendorId)
                    ->where('is_read', false)
                    ->update(['is_read' => true]);
            }
            
            // Unread count
            $unreadCount = DB::table('vendor_notifications')
                ->where('vendor_id', $vendorId)
                ->where('is_read', false)
                ->count();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'notifications' => $notifications,
                    'unread_count' => $unreadCount
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notifications',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Mark notification as read
     */
    public function markNotificationRead(Request $request, $id)
    {
        try {
            $vendorId = $request->user()->id;
            
            $notification = DB::table('vendor_notifications')
                ->where('id', $id)
                ->where('vendor_id', $vendorId)
                ->first();
            
            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found'
                ], 404);
            }
            
            DB::table('vendor_notifications')
                ->where('id', $id)
                ->where('vendor_id', $vendorId)
                ->update(['is_read' => true]);
            
            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}