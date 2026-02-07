<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerController extends Controller
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
            
            // Check if customer exists
            $customerExists = DB::table('customers')
                ->where('phone', $mobile)
                ->orWhere('email', $email)
                ->first();
            
            if ($customerExists) {
                // Existing customer - login
                $customerId = $customerExists->id;
                
                // Update or create auth record
                DB::table('customer_auth')->updateOrInsert(
                    ['customer_id' => $customerId],
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
                    'otp' => $otp // Remove in production
                ]);
            } else {
                // New customer - registration
                $tempCustomerId = Str::random(20);
                
                // Store in temp table
                DB::table('temp_customer_auth')->updateOrInsert(
                    ['mobile' => $mobile],
                    [
                        'email' => $email,
                        'otp' => $otp,
                        'otp_expires_at' => $otpExpires,
                        'temp_data' => json_encode([
                            'name' => $request->name,
                            'email' => $email
                        ]),
                        'created_at' => now()
                    ]
                );
                
                return response()->json([
                    'success' => true,
                    'message' => 'OTP sent to mobile',
                    'is_existing' => false,
                    'otp' => $otp // Remove in production
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
            'otp' => 'required|string|size:6',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'fcm_token' => 'nullable|string'
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
            
            // Check for existing customer
            $customerAuth = DB::table('customer_auth')
                ->where('mobile', $mobile)
                ->first();
            
            if ($customerAuth) {
                // Existing customer login
                if ($customerAuth->otp !== $otp) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid OTP'
                    ], 400);
                }
                
                if (now()->gt($customerAuth->otp_expires_at)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'OTP expired'
                    ], 400);
                }
                
                // Get customer details
                $customer = DB::table('customers')->find($customerAuth->customer_id);
                
                if (!$customer) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Customer not found'
                    ], 404);
                }
                
                // Check if customer is active
                if ($customer->status !== 'active') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Your account is ' . $customer->status
                    ], 403);
                }
                
                // Create Sanctum token
                $token = DB::table('personal_access_tokens')->insertGetId([
                    'tokenable_type' => 'App\\Models\\Customer',
                    'tokenable_id' => $customer->id,
                    'name' => 'customer-api-token',
                    'token' => hash('sha256', Str::random(40)),
                    'abilities' => '["*"]',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                // Update auth
                DB::table('customer_auth')
                    ->where('id', $customerAuth->id)
                    ->update([
                        'otp' => null,
                        'otp_expires_at' => null,
                        'phone_verified_at' => now(),
                        'fcm_token' => $request->fcm_token,
                        'last_login_at' => now(),
                        'updated_at' => now()
                    ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Login successful',
                    'token' => $token,
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'email' => $customer->email,
                        'phone' => $customer->phone,
                        'profile_image' => $customer->profile_image,
                        'status' => $customer->status
                    ]
                ]);
            } else {
                // New customer registration
                $tempAuth = DB::table('temp_customer_auth')
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
                
                // Create new customer
                $customerId = DB::table('customers')->insertGetId([
                    'name' => $request->name ?? 'Customer',
                    'email' => $request->email ?? $tempAuth->email,
                    'phone' => $mobile,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                // Create auth record
                DB::table('customer_auth')->insert([
                    'customer_id' => $customerId,
                    'mobile' => $mobile,
                    'email' => $request->email ?? $tempAuth->email,
                    'phone_verified_at' => now(),
                    'fcm_token' => $request->fcm_token,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                // Clear temp data
                DB::table('temp_customer_auth')->where('id', $tempAuth->id)->delete();
                
                // Create token
                $token = DB::table('personal_access_tokens')->insertGetId([
                    'tokenable_type' => 'App\\Models\\Customer',
                    'tokenable_id' => $customerId,
                    'name' => 'customer-api-token',
                    'token' => hash('sha256', Str::random(40)),
                    'abilities' => '["*"]',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Registration successful',
                    'token' => $token,
                    'customer' => [
                        'id' => $customerId,
                        'name' => $request->name ?? 'Customer',
                        'email' => $request->email ?? $tempAuth->email,
                        'phone' => $mobile,
                        'status' => 'active'
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
     * Logout
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
    
    // ==================== PROFILE ====================
    
    /**
     * Get customer profile
     */
    public function getProfile(Request $request)
    {
        try {
            $customerId = $request->user()->id;
            
            $customer = DB::table('customers')->find($customerId);
            
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }
            
            // Get stats
            $totalOrders = DB::table('orders')
                ->where('customer_id', $customerId)
                ->count();
            
            $totalSpent = DB::table('orders')
                ->where('customer_id', $customerId)
                ->where('payment_status', 'paid')
                ->sum('final_amount');
            
            $pendingOrders = DB::table('orders')
                ->where('customer_id', $customerId)
                ->whereIn('order_status', ['pending', 'confirmed', 'processing', 'ready', 'picked_up', 'on_the_way'])
                ->count();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'profile' => $customer,
                    'stats' => [
                        'total_orders' => $totalOrders,
                        'total_spent' => (float) $totalSpent,
                        'pending_orders' => $pendingOrders
                    ]
                ]
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
     * Update profile
     */
    public function updateProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'email' => 'email|unique:customers,email,' . $request->user()->id,
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'profile_image' => 'nullable|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $customerId = $request->user()->id;
            
            $updateData = [];
            if ($request->has('name')) $updateData['name'] = $request->name;
            if ($request->has('email')) $updateData['email'] = $request->email;
            if ($request->has('date_of_birth')) $updateData['date_of_birth'] = $request->date_of_birth;
            if ($request->has('gender')) $updateData['gender'] = $request->gender;
            if ($request->has('profile_image')) $updateData['profile_image'] = $request->profile_image;
            
            $updateData['updated_at'] = now();
            
            DB::table('customers')->where('id', $customerId)->update($updateData);
            
            // Update email in auth if changed
            if ($request->has('email')) {
                DB::table('customer_auth')
                    ->where('customer_id', $customerId)
                    ->update(['email' => $request->email, 'updated_at' => now()]);
            }
            
            $updatedCustomer = DB::table('customers')->find($customerId);
            
            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $updatedCustomer
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== HOME & PRODUCTS ====================
    
    /**
     * Get home page data
     */
    public function getHomeData(Request $request)
    {
        try {
            // Featured products
            $featuredProducts = DB::table('products')
                ->join('vendors', 'products.vendor_id', '=', 'vendors.id')
                ->where('products.is_active', true)
                ->where('products.is_featured', true)
                ->where('vendors.status', 'active')
                ->select(
                    'products.*',
                    'vendors.business_name as vendor_name'
                )
                ->orderBy('products.created_at', 'desc')
                ->limit(10)
                ->get();
            
            // Categories with product count
            $categories = DB::table('categories')
                ->where('is_active', true)
                ->whereNull('parent_id')
                ->select('id', 'name', 'image', 'slug')
                ->orderBy('display_order')
                ->limit(8)
                ->get()
                ->map(function($category) {
                    $category->product_count = DB::table('products')
                        ->where('category_id', $category->id)
                        ->where('is_active', true)
                        ->count();
                    return $category;
                });
            
            // Recent products
            $recentProducts = DB::table('products')
                ->join('vendors', 'products.vendor_id', '=', 'vendors.id')
                ->where('products.is_active', true)
                ->where('vendors.status', 'active')
                ->select(
                    'products.*',
                    'vendors.business_name as vendor_name'
                )
                ->orderBy('products.created_at', 'desc')
                ->limit(8)
                ->get();
            
            // Banners (you would have a banners table in real app)
            $banners = [
                ['id' => 1, 'image' => 'banner1.jpg', 'link' => '/category/electronics'],
                ['id' => 2, 'image' => 'banner2.jpg', 'link' => '/category/fashion'],
                ['id' => 3, 'image' => 'banner3.jpg', 'link' => '/offer/summer-sale']
            ];
            
            return response()->json([
                'success' => true,
                'data' => [
                    'banners' => $banners,
                    'categories' => $categories,
                    'featured_products' => $featuredProducts,
                    'recent_products' => $recentProducts
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch home data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get all categories
     */
    public function getCategories(Request $request)
    {
        try {
            $categories = DB::table('categories')
                ->where('is_active', true)
                ->orderBy('display_order')
                ->get()
                ->map(function($category) {
                    $category->subcategories = DB::table('categories')
                        ->where('parent_id', $category->id)
                        ->where('is_active', true)
                        ->orderBy('display_order')
                        ->get();
                    return $category;
                });
            
            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get products by category
     */
    public function getProductsByCategory(Request $request, $categoryId)
    {
        try {
            $query = DB::table('products')
                ->join('vendors', 'products.vendor_id', '=', 'vendors.id')
                ->where('products.is_active', true)
                ->where('vendors.status', 'active')
                ->select(
                    'products.*',
                    'vendors.business_name as vendor_name'
                );
            
            // Get all subcategory IDs
            $subcategoryIds = DB::table('categories')
                ->where('parent_id', $categoryId)
                ->pluck('id')
                ->toArray();
            
            $categoryIds = array_merge([$categoryId], $subcategoryIds);
            $query->whereIn('products.category_id', $categoryIds);
            
            // Filter by price
            if ($request->has('min_price')) {
                $query->where('products.price', '>=', $request->min_price);
            }
            if ($request->has('max_price')) {
                $query->where('products.price', '<=', $request->max_price);
            }
            
            // Sort
            $sortBy = $request->sort_by ?? 'created_at';
            $sortOrder = $request->sort_order ?? 'desc';
            $query->orderBy($sortBy, $sortOrder);
            
            // Pagination
            $perPage = $request->per_page ?? 20;
            $products = $query->paginate($perPage);
            
            // Get category info
            $category = DB::table('categories')->find($categoryId);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'category' => $category,
                    'products' => $products
                ]
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
     * Search products
     */
    public function searchProducts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:2'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $query = $request->get('query');

            $customerId = $request->user()->id;
            
            // Save search history
            DB::table('customer_search_history')->updateOrInsert(
                ['customer_id' => $customerId, 'search_term' => $query],
                [
                    'search_count' => DB::raw('search_count + 1'),
                    'last_searched_at' => now()
                   
                ]
            );
            
            // Search products
            $products = DB::table('products')
                ->join('vendors', 'products.vendor_id', '=', 'vendors.id')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->where('products.is_active', true)
                ->where('vendors.status', 'active')
                ->where(function($q) use ($query) {
                    $q->where('products.name', 'like', "%{$query}%")
                      ->orWhere('products.description', 'like', "%{$query}%")
                      ->orWhere('categories.name', 'like', "%{$query}%")
                      ->orWhere('vendors.business_name', 'like', "%{$query}%");
                })
                ->select(
                    'products.*',
                    'vendors.business_name as vendor_name',
                    'categories.name as category_name'
                )
                ->orderBy('products.created_at', 'desc')
                ->limit(50)
                ->get();
            
            // Search categories
            $categories = DB::table('categories')
                ->where('is_active', true)
                ->where('name', 'like', "%{$query}%")
                ->limit(5)
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'products' => $products,
                    'categories' => $categories,
                    'search_term' => $query
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search products',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get product details
     */
    public function getProductDetails(Request $request, $productId)
    {
        try {
            $product = DB::table('products')
                ->join('vendors', 'products.vendor_id', '=', 'vendors.id')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->where('products.id', $productId)
                ->where('products.is_active', true)
                ->where('vendors.status', 'active')
                ->select(
                    'products.*',
                    'vendors.business_name as vendor_name',
                    'categories.name as category_name'
                )
                ->first();
            
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }
            
            // Get product attributes
            $attributes = DB::table('product_attributes')
                ->where('product_id', $productId)
                ->get();
            
            // Get reviews
            $reviews = DB::table('order_reviews')
                ->join('customers', 'order_reviews.customer_id', '=', 'customers.id')
                ->where('order_reviews.product_id', $productId)
                ->where('order_reviews.is_approved', true)
                ->select(
                    'order_reviews.*',
                    'customers.name as customer_name',
                    'customers.profile_image as customer_image'
                )
                ->orderBy('order_reviews.created_at', 'desc')
                ->limit(10)
                ->get();
            
            // Average rating
            $avgRating = DB::table('order_reviews')
                ->where('product_id', $productId)
                ->where('is_approved', true)
                ->avg('rating');
            
            // Related products
            $relatedProducts = DB::table('products')
                ->join('vendors', 'products.vendor_id', '=', 'vendors.id')
                ->where('products.category_id', $product->category_id)
                ->where('products.id', '!=', $productId)
                ->where('products.is_active', true)
                ->where('vendors.status', 'active')
                ->select(
                    'products.id',
                    'products.name',
                    'products.price',
                    'products.discounted_price',
                    'products.images',
                    'vendors.business_name as vendor_name'
                )
                ->limit(8)
                ->get();
            
            // Check if in wishlist
            $customerId = $request->user()->id;
            $inWishlist = DB::table('customer_wishlist')
                ->where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->exists();
            
            // Check cart quantity
            $cartItem = DB::table('customer_cart')
                ->where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->first();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'product' => $product,
                    'attributes' => $attributes,
                    'reviews' => $reviews,
                    'average_rating' => round($avgRating, 2),
                    'related_products' => $relatedProducts,
                    'in_wishlist' => $inWishlist,
                    'cart_quantity' => $cartItem ? $cartItem->quantity : 0
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch product details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== CART MANAGEMENT ====================
    
    /**
     * Get cart items
     */
    public function getCart(Request $request)
    {
        try {
            $customerId = $request->user()->id;
            
            $cartItems = DB::table('customer_cart')
                ->join('products', 'customer_cart.product_id', '=', 'products.id')
                ->join('vendors', 'customer_cart.vendor_id', '=', 'vendors.id')
                ->where('customer_cart.customer_id', $customerId)
                ->select(
                    'customer_cart.*',
                    'products.name as product_name',
                    'products.images',
                    'products.stock_quantity',
                    'products.min_order_quantity',
                    'products.max_order_quantity',
                    'vendors.business_name as vendor_name'
                )
                ->get();
            
            // Calculate totals
            $subtotal = 0;
            foreach ($cartItems as $item) {
                $subtotal += $item->price * $item->quantity;
            }
            
            // Mock delivery charge
            $deliveryCharge = $subtotal > 0 ? 40 : 0;
            $total = $subtotal + $deliveryCharge;
            
            return response()->json([
                'success' => true,
                'data' => [
                    'items' => $cartItems,
                    'summary' => [
                        'subtotal' => (float) $subtotal,
                        'delivery_charge' => (float) $deliveryCharge,
                        'total' => (float) $total,
                        'item_count' => count($cartItems)
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Add to cart
     */
    public function addToCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $customerId = $request->user()->id;
            
            // Get product
            $product = DB::table('products')
                ->where('id', $request->product_id)
                ->where('is_active', true)
                ->first();
            
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not available'
                ], 404);
            }
            
            // Check stock
            if ($product->stock_quantity < $request->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock'
                ], 400);
            }
            
            // Check min/max order
            if ($request->quantity < $product->min_order_quantity) {
                return response()->json([
                    'success' => false,
                    'message' => "Minimum order quantity is {$product->min_order_quantity}"
                ], 400);
            }
            
            if ($product->max_order_quantity && $request->quantity > $product->max_order_quantity) {
                return response()->json([
                    'success' => false,
                    'message' => "Maximum order quantity is {$product->max_order_quantity}"
                ], 400);
            }
            
            // Calculate price (use discounted if available)
            $price = $product->discounted_price ?? $product->price;
            
            // Check if already in cart
            $existingCartItem = DB::table('customer_cart')
                ->where('customer_id', $customerId)
                ->where('product_id', $request->product_id)
                ->where('vendor_id', $product->vendor_id)
                ->first();
            
            if ($existingCartItem) {
                // Update quantity
                $newQuantity = $existingCartItem->quantity + $request->quantity;
                
                // Check stock again
                if ($product->stock_quantity < $newQuantity) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Insufficient stock for additional quantity'
                    ], 400);
                }
                
                DB::table('customer_cart')
                    ->where('id', $existingCartItem->id)
                    ->update([
                        'quantity' => $newQuantity,
                        'price' => $price,
                        'notes' => $request->notes ?? $existingCartItem->notes,
                        'updated_at' => now()
                    ]);
                
                $message = 'Cart updated successfully';
            } else {
                // Add new item
                DB::table('customer_cart')->insert([
                    'customer_id' => $customerId,
                    'product_id' => $request->product_id,
                    'vendor_id' => $product->vendor_id,
                    'quantity' => $request->quantity,
                    'price' => $price,
                    'notes' => $request->notes,
                    'added_at' => now(),
                    'updated_at' => now()
                ]);
                
                $message = 'Added to cart successfully';
            }
            
            return response()->json([
                'success' => true,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add to cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update cart item quantity
     */
    public function updateCartItem(Request $request, $cartItemId)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $customerId = $request->user()->id;
            
            // Get cart item
            $cartItem = DB::table('customer_cart')
                ->where('id', $cartItemId)
                ->where('customer_id', $customerId)
                ->first();
            
            if (!$cartItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart item not found'
                ], 404);
            }
            
            // Get product
            $product = DB::table('products')->find($cartItem->product_id);
            
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }
            
            // Check stock
            if ($product->stock_quantity < $request->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock'
                ], 400);
            }
            
            // Check min/max order
            if ($request->quantity < $product->min_order_quantity) {
                return response()->json([
                    'success' => false,
                    'message' => "Minimum order quantity is {$product->min_order_quantity}"
                ], 400);
            }
            
            if ($product->max_order_quantity && $request->quantity > $product->max_order_quantity) {
                return response()->json([
                    'success' => false,
                    'message' => "Maximum order quantity is {$product->max_order_quantity}"
                ], 400);
            }
            
            // Update quantity
            DB::table('customer_cart')
                ->where('id', $cartItemId)
                ->where('customer_id', $customerId)
                ->update([
                    'quantity' => $request->quantity,
                    'updated_at' => now()
                ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Cart item updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update cart item',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Remove from cart
     */
    public function removeFromCart(Request $request, $cartItemId)
    {
        try {
            $customerId = $request->user()->id;
            
            $deleted = DB::table('customer_cart')
                ->where('id', $cartItemId)
                ->where('customer_id', $customerId)
                ->delete();
            
            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart item not found'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Removed from cart successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove from cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Clear cart
     */
    public function clearCart(Request $request)
    {
        try {
            $customerId = $request->user()->id;
            
            DB::table('customer_cart')
                ->where('customer_id', $customerId)
                ->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Cart cleared successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== WISHLIST ====================
    
    /**
     * Get wishlist
     */
    public function getWishlist(Request $request)
    {
        try {
            $customerId = $request->user()->id;
            
            $wishlist = DB::table('customer_wishlist')
                ->join('products', 'customer_wishlist.product_id', '=', 'products.id')
                ->join('vendors', 'products.vendor_id', '=', 'vendors.id')
                ->where('customer_wishlist.customer_id', $customerId)
                ->where('products.is_active', true)
                ->where('vendors.status', 'active')
                ->select(
                    'customer_wishlist.*',
                    'products.name',
                    'products.price',
                    'products.discounted_price',
                    'products.images',
                    'products.stock_quantity',
                    'vendors.business_name as vendor_name'
                )
                ->orderBy('customer_wishlist.added_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $wishlist
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch wishlist',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Add to wishlist
     */
    public function addToWishlist(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $customerId = $request->user()->id;
            
            // Check if already in wishlist
            $exists = DB::table('customer_wishlist')
                ->where('customer_id', $customerId)
                ->where('product_id', $request->product_id)
                ->exists();
            
            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Already in wishlist'
                ], 400);
            }
            
            // Add to wishlist
            DB::table('customer_wishlist')->insert([
                'customer_id' => $customerId,
                'product_id' => $request->product_id,
                'added_at' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Added to wishlist successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add to wishlist',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Remove from wishlist
     */
    public function removeFromWishlist(Request $request, $productId)
    {
        try {
            $customerId = $request->user()->id;
            
            $deleted = DB::table('customer_wishlist')
                ->where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->delete();
            
            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item not in wishlist'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Removed from wishlist successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove from wishlist',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== ADDRESS MANAGEMENT ====================
    
    /**
     * Get addresses
     */
    public function getAddresses(Request $request)
    {
        try {
            $customerId = $request->user()->id;
            
            $addresses = DB::table('customer_addresses')
                ->where('customer_id', $customerId)
                ->orderBy('is_default', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $addresses
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch addresses',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Add address
     */
    public function addAddress(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address_type' => 'required|in:home,work,other',
            'full_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'address_line1' => 'required|string',
            'address_line2' => 'nullable|string',
            'landmark' => 'nullable|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'country' => 'required|string|max:100',
            'postal_code' => 'required|string|max:20',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'is_default' => 'boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $customerId = $request->user()->id;
            
            // If setting as default, remove default from others
            if ($request->is_default) {
                DB::table('customer_addresses')
                    ->where('customer_id', $customerId)
                    ->update(['is_default' => false]);
            }
            
            $addressId = DB::table('customer_addresses')->insertGetId([
                'customer_id' => $customerId,
                'address_type' => $request->address_type,
                'full_name' => $request->full_name,
                'phone' => $request->phone,
                'address_line1' => $request->address_line1,
                'address_line2' => $request->address_line2,
                'landmark' => $request->landmark,
                'city' => $request->city,
                'state' => $request->state,
                'country' => $request->country,
                'postal_code' => $request->postal_code,
                'lat' => $request->lat,
                'lng' => $request->lng,
                'is_default' => $request->is_default ?? false,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            $address = DB::table('customer_addresses')->find($addressId);
            
            return response()->json([
                'success' => true,
                'message' => 'Address added successfully',
                'data' => $address
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add address',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update address
     */
    public function updateAddress(Request $request, $addressId)
    {
        $validator = Validator::make($request->all(), [
            'address_type' => 'in:home,work,other',
            'full_name' => 'string|max:255',
            'phone' => 'string|max:20',
            'address_line1' => 'string',
            'address_line2' => 'nullable|string',
            'landmark' => 'nullable|string|max:255',
            'city' => 'string|max:100',
            'state' => 'string|max:100',
            'country' => 'string|max:100',
            'postal_code' => 'string|max:20',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'is_default' => 'boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $customerId = $request->user()->id;
            
            // Check if address belongs to customer
            $address = DB::table('customer_addresses')
                ->where('id', $addressId)
                ->where('customer_id', $customerId)
                ->first();
            
            if (!$address) {
                return response()->json([
                    'success' => false,
                    'message' => 'Address not found'
                ], 404);
            }
            
            // If setting as default, remove default from others
            if ($request->is_default) {
                DB::table('customer_addresses')
                    ->where('customer_id', $customerId)
                    ->where('id', '!=', $addressId)
                    ->update(['is_default' => false]);
            }
            
            $updateData = [];
            if ($request->has('address_type')) $updateData['address_type'] = $request->address_type;
            if ($request->has('full_name')) $updateData['full_name'] = $request->full_name;
            if ($request->has('phone')) $updateData['phone'] = $request->phone;
            if ($request->has('address_line1')) $updateData['address_line1'] = $request->address_line1;
            if ($request->has('address_line2')) $updateData['address_line2'] = $request->address_line2;
            if ($request->has('landmark')) $updateData['landmark'] = $request->landmark;
            if ($request->has('city')) $updateData['city'] = $request->city;
            if ($request->has('state')) $updateData['state'] = $request->state;
            if ($request->has('country')) $updateData['country'] = $request->country;
            if ($request->has('postal_code')) $updateData['postal_code'] = $request->postal_code;
            if ($request->has('lat')) $updateData['lat'] = $request->lat;
            if ($request->has('lng')) $updateData['lng'] = $request->lng;
            if ($request->has('is_default')) $updateData['is_default'] = $request->is_default;
            
            $updateData['updated_at'] = now();
            
            DB::table('customer_addresses')
                ->where('id', $addressId)
                ->where('customer_id', $customerId)
                ->update($updateData);
            
            $updatedAddress = DB::table('customer_addresses')->find($addressId);
            
            return response()->json([
                'success' => true,
                'message' => 'Address updated successfully',
                'data' => $updatedAddress
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update address',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Delete address
     */
    public function deleteAddress(Request $request, $addressId)
    {
        try {
            $customerId = $request->user()->id;
            
            $deleted = DB::table('customer_addresses')
                ->where('id', $addressId)
                ->where('customer_id', $customerId)
                ->delete();
            
            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Address not found'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Address deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete address',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Set default address
     */
    public function setDefaultAddress(Request $request, $addressId)
    {
        try {
            $customerId = $request->user()->id;
            
            // Check if address belongs to customer
            $address = DB::table('customer_addresses')
                ->where('id', $addressId)
                ->where('customer_id', $customerId)
                ->first();
            
            if (!$address) {
                return response()->json([
                    'success' => false,
                    'message' => 'Address not found'
                ], 404);
            }
            
            // Remove default from all addresses
            DB::table('customer_addresses')
                ->where('customer_id', $customerId)
                ->update(['is_default' => false]);
            
            // Set this as default
            DB::table('customer_addresses')
                ->where('id', $addressId)
                ->where('customer_id', $customerId)
                ->update(['is_default' => true, 'updated_at' => now()]);
            
            return response()->json([
                'success' => true,
                'message' => 'Default address updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to set default address',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== ORDERS ====================
    
    /**
     * Get orders
     */
    public function getOrders(Request $request)
    {
        try {
            $customerId = $request->user()->id;
            
            $query = DB::table('orders')
                ->leftJoin('vendors', 'orders.vendor_id', '=', 'vendors.id')
                ->leftJoin('delivery_partners', 'orders.delivery_partner_id', '=', 'delivery_partners.id')
                ->where('orders.customer_id', $customerId)
                ->select(
                    'orders.*',
                    'vendors.business_name as vendor_name',
                    'delivery_partners.name as delivery_partner_name'
                );
            
            // Filter by status
            if ($request->has('status')) {
                if ($request->status === 'ongoing') {
                    $query->whereIn('orders.order_status', ['pending', 'confirmed', 'processing', 'ready', 'picked_up', 'on_the_way']);
                } elseif ($request->status === 'completed') {
                    $query->where('orders.order_status', 'delivered');
                } elseif ($request->status === 'cancelled') {
                    $query->whereIn('orders.order_status', ['cancelled', 'rejected']);
                } else {
                    $query->where('orders.order_status', $request->status);
                }
            }
            
            $orders = $query->orderBy('orders.created_at', 'desc')->get();
            
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
    public function getOrderDetails(Request $request, $orderId)
    {
        try {
            $customerId = $request->user()->id;
            
            // Check if order belongs to customer
            $order = DB::table('orders')
                ->leftJoin('vendors', 'orders.vendor_id', '=', 'vendors.id')
                ->leftJoin('delivery_partners', 'orders.delivery_partner_id', '=', 'delivery_partners.id')
                ->where('orders.id', $orderId)
                ->where('orders.customer_id', $customerId)
                ->select(
                    'orders.*',
                    'vendors.business_name as vendor_name',
                    'vendors.phone as vendor_phone',
                    'delivery_partners.name as delivery_partner_name',
                    'delivery_partners.phone as delivery_partner_phone',
                    'delivery_partners.vehicle_number'
                )
                ->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }
            
            // Get order items
            $orderItems = DB::table('order_items')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->where('order_items.order_id', $orderId)
                ->select(
                    'order_items.*',
                    'products.name as product_name',
                    'products.images'
                )
                ->get();
            
            // Get order status history
            $statusHistory = DB::table('order_status_history')
                ->where('order_id', $orderId)
                ->orderBy('created_at', 'desc')
                ->get();
            
            // Get delivery tracking if available
            $deliveryTracking = null;
            if ($order->delivery_partner_id) {
                $deliveryTracking = DB::table('delivery_tasks')
                    ->where('order_id', $orderId)
                    ->where('partner_id', $order->delivery_partner_id)
                    ->first();
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'order' => $order,
                    'items' => $orderItems,
                    'status_history' => $statusHistory,
                    'delivery_tracking' => $deliveryTracking
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
     * Place order
     */
    public function placeOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address_id' => 'required|exists:customer_addresses,id',
            'payment_method' => 'required|in:cod,online,card,wallet',
            'notes' => 'nullable|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $customerId = $request->user()->id;
            
            // Get cart items
            $cartItems = DB::table('customer_cart')
                ->join('products', 'customer_cart.product_id', '=', 'products.id')
                ->join('vendors', 'customer_cart.vendor_id', '=', 'vendors.id')
                ->where('customer_cart.customer_id', $customerId)
                ->select(
                    'customer_cart.*',
                    'products.name as product_name',
                    'products.stock_quantity',
                    'vendors.business_name'
                )
                ->get();
            
            if ($cartItems->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your cart is empty'
                ], 400);
            }
            
            // Check stock for all items
            foreach ($cartItems as $item) {
                if ($item->stock_quantity < $item->quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient stock for {$item->product_name}"
                    ], 400);
                }
            }
            
            // Get address
            $address = DB::table('customer_addresses')
                ->where('id', $request->address_id)
                ->where('customer_id', $customerId)
                ->first();
            
            if (!$address) {
                return response()->json([
                    'success' => false,
                    'message' => 'Address not found'
                ], 404);
            }
            
            // Group by vendor
            $vendorGroups = [];
            foreach ($cartItems as $item) {
                $vendorId = $item->vendor_id;
                if (!isset($vendorGroups[$vendorId])) {
                    $vendorGroups[$vendorId] = [
                        'vendor_id' => $vendorId,
                        'vendor_name' => $item->business_name,
                        'items' => []
                    ];
                }
                $vendorGroups[$vendorId]['items'][] = $item;
            }
            
            $orderIds = [];
            
            // Create order for each vendor
            foreach ($vendorGroups as $vendorGroup) {
                // Calculate totals
                $subtotal = 0;
                foreach ($vendorGroup['items'] as $item) {
                    $subtotal += $item->price * $item->quantity;
                }
                
                $deliveryCharge = 40; // Fixed for now
                $total = $subtotal + $deliveryCharge;
                
                // Generate order number
                $orderNumber = 'ORD' . now()->format('Ymd') . strtoupper(Str::random(6));
                
                // Create order
                $orderId = DB::table('orders')->insertGetId([
                    'order_number' => $orderNumber,
                    'customer_id' => $customerId,
                    'vendor_id' => $vendorGroup['vendor_id'],
                    'total_amount' => $subtotal,
                    'delivery_charge' => $deliveryCharge,
                    'final_amount' => $total,
                    'payment_method' => $request->payment_method,
                    'payment_status' => $request->payment_method === 'cod' ? 'pending' : 'paid',
                    'order_status' => 'pending',
                    'delivery_address' => "{$address->address_line1}, {$address->address_line2}, {$address->city}, {$address->state}, {$address->country} - {$address->postal_code}",
                    'customer_notes' => $request->notes,
                    'estimated_delivery_time' => now()->addHours(2),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                $orderIds[] = $orderId;
                
                // Add order items
                foreach ($vendorGroup['items'] as $item) {
                    DB::table('order_items')->insert([
                        'order_id' => $orderId,
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->price,
                        'total_price' => $item->price * $item->quantity,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    
                    // Update product stock
                    DB::table('products')
                        ->where('id', $item->product_id)
                        ->decrement('stock_quantity', $item->quantity);
                }
                
                // Create vendor order
                DB::table('vendor_orders')->insert([
                    'order_id' => $orderId,
                    'vendor_id' => $vendorGroup['vendor_id'],
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
            
            // Clear cart
            DB::table('customer_cart')
                ->where('customer_id', $customerId)
                ->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully',
                'data' => [
                    'order_ids' => $orderIds,
                    'order_numbers' => DB::table('orders')->whereIn('id', $orderIds)->pluck('order_number')
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to place order',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Cancel order
     */
    public function cancelOrder(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $customerId = $request->user()->id;
            
            // Check if order belongs to customer and can be cancelled
            $order = DB::table('orders')
                ->where('id', $orderId)
                ->where('customer_id', $customerId)
                ->whereIn('order_status', ['pending', 'confirmed'])
                ->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order cannot be cancelled'
                ], 400);
            }
            
            // Update order status
            DB::table('orders')->where('id', $orderId)->update([
                'order_status' => 'cancelled',
                'cancellation_reason' => $request->reason,
                'updated_at' => now()
            ]);
            
            // Update vendor order
            DB::table('vendor_orders')
                ->where('order_id', $orderId)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
            
            // Restore product stock
            $orderItems = DB::table('order_items')->where('order_id', $orderId)->get();
            foreach ($orderItems as $item) {
                DB::table('products')
                    ->where('id', $item->product_id)
                    ->increment('stock_quantity', $item->quantity);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Order cancelled successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== REVIEWS ====================
    
    /**
     * Submit review
     */
    public function submitReview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
            'product_id' => 'required|exists:products,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string',
            'images' => 'nullable|array'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $customerId = $request->user()->id;
            
            // Check if order belongs to customer and is delivered
            $order = DB::table('orders')
                ->where('id', $request->order_id)
                ->where('customer_id', $customerId)
                ->where('order_status', 'delivered')
                ->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found or not delivered'
                ], 400);
            }
            
            // Check if product is in order
            $orderItem = DB::table('order_items')
                ->where('order_id', $request->order_id)
                ->where('product_id', $request->product_id)
                ->exists();
            
            if (!$orderItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found in order'
                ], 400);
            }
            
            // Check if already reviewed
            $existingReview = DB::table('order_reviews')
                ->where('order_id', $request->order_id)
                ->where('product_id', $request->product_id)
                ->where('customer_id', $customerId)
                ->exists();
            
            if ($existingReview) {
                return response()->json([
                    'success' => false,
                    'message' => 'Already reviewed this product'
                ], 400);
            }
            
            // Submit review
            DB::table('order_reviews')->insert([
                'order_id' => $request->order_id,
                'product_id' => $request->product_id,
                'customer_id' => $customerId,
                'rating' => $request->rating,
                'comment' => $request->comment,
                'images' => $request->images ? json_encode($request->images) : null,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit review',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== NOTIFICATIONS ====================
    
    /**
     * Get notifications
     */
    public function getNotifications(Request $request)
    {
        try {
            $customerId = $request->user()->id;
            
            $query = DB::table('customer_notifications')
                ->where('customer_id', $customerId);
            
            // Filter by read status
            if ($request->has('is_read')) {
                $query->where('is_read', $request->is_read);
            }
            
            $notifications = $query->orderBy('created_at', 'desc')->get();
            
            // Mark as read if requested
            if ($request->has('mark_read') && $request->mark_read) {
                DB::table('customer_notifications')
                    ->where('customer_id', $customerId)
                    ->where('is_read', false)
                    ->update(['is_read' => true]);
            }
            
            // Unread count
            $unreadCount = DB::table('customer_notifications')
                ->where('customer_id', $customerId)
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
    public function markNotificationRead(Request $request, $notificationId)
    {
        try {
            $customerId = $request->user()->id;
            
            $notification = DB::table('customer_notifications')
                ->where('id', $notificationId)
                ->where('customer_id', $customerId)
                ->first();
            
            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found'
                ], 404);
            }
            
            DB::table('customer_notifications')
                ->where('id', $notificationId)
                ->where('customer_id', $customerId)
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