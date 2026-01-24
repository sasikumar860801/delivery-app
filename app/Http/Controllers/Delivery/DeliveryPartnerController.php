<?php

namespace App\Http\Controllers\Delivery;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
class DeliveryPartnerController extends Controller
{
    // ==================== AUTHENTICATION ====================
    
    /**
     * Send OTP for login
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string|max:20'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $mobile = $request->mobile;
            
            // Check if delivery partner exists
            $partner = DB::table('delivery_partners')
                ->where('phone', $mobile)
                ->first();
            
            if (!$partner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery partner not found. Please contact admin.'
                ], 404);
            }
            
            // Check if partner is approved
            if ($partner->status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => "Your account is {$partner->status}. Please contact admin."
                ], 403);
            }
            
            // Generate OTP
            $otp = rand(100000, 999999);
            $otpExpires = now()->addMinutes(10);
            
            // Update or create auth record
            DB::table('delivery_partner_auth')->updateOrInsert(
                ['partner_id' => $partner->id],
                [
                    'mobile' => $mobile,
                    'otp' => $otp,
                    'otp_expires_at' => $otpExpires,
                    'updated_at' => now()
                ]
            );
            
            return response()->json([
                'success' => true,
                'message' => 'OTP sent to mobile',
                'otp' => $otp // Remove in production
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Verify OTP and login
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|string',
            'otp' => 'required|string|size:6',
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
            
            // Get partner
            $partner = DB::table('delivery_partners')
                ->where('phone', $mobile)
                ->first();
            
            if (!$partner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery partner not found'
                ], 404);
            }
            
            // Check status
            if ($partner->status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => "Account is {$partner->status}"
                ], 403);
            }
            
            // Get auth
            $partnerAuth = DB::table('delivery_partner_auth')
                ->where('partner_id', $partner->id)
                ->first();
            
            if (!$partnerAuth || $partnerAuth->otp !== $otp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid OTP'
                ], 400);
            }
            
            if (now()->gt($partnerAuth->otp_expires_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'OTP expired'
                ], 400);
            }
            
            // Create Sanctum token
            $token = DB::table('personal_access_tokens')->insertGetId([
                'tokenable_type' => 'App\\Models\\DeliveryPartner',
                'tokenable_id' => $partner->id,
                'name' => 'delivery-api-token',
                'token' => hash('sha256', Str::random(40)),
                'abilities' => '["*"]',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            // Update auth
            DB::table('delivery_partner_auth')
                ->where('id', $partnerAuth->id)
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
                'partner' => [
                    'id' => $partner->id,
                    'name' => $partner->name,
                    'phone' => $partner->phone,
                    'vehicle_type' => $partner->vehicle_type,
                    'vehicle_number' => $partner->vehicle_number,
                    'status' => $partner->status
                ]
            ]);
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
    
    // ==================== DASHBOARD & PROFILE ====================
    
    /**
     * Get dashboard stats
     */
    public function dashboard(Request $request)
    {
        try {
            $partnerId = $request->user()->id;
            
            // Today's stats
            $today = now()->format('Y-m-d');
            
            $todayTasks = DB::table('delivery_tasks')
                ->where('partner_id', $partnerId)
                ->whereDate('created_at', $today)
                ->count();
            
            $todayEarnings = DB::table('delivery_earnings')
                ->where('partner_id', $partnerId)
                ->whereDate('created_at', $today)
                ->where('payment_status', 'paid')
                ->sum('total_amount');
            
            // Overall stats
            $totalTasks = DB::table('delivery_tasks')
                ->where('partner_id', $partnerId)
                ->whereIn('status', ['delivered', 'completed'])
                ->count();
            
            $totalEarnings = DB::table('delivery_earnings')
                ->where('partner_id', $partnerId)
                ->where('payment_status', 'paid')
                ->sum('total_amount');
            
            // Active tasks
            $activeTasks = DB::table('delivery_tasks')
                ->where('partner_id', $partnerId)
                ->whereIn('status', ['assigned', 'accepted', 'picked_up', 'on_the_way'])
                ->count();
            
            // Average rating
            $avgRating = DB::table('delivery_ratings')
                ->where('partner_id', $partnerId)
                ->avg('rating');
            
            // Recent tasks
            $recentTasks = DB::table('delivery_tasks')
                ->join('orders', 'delivery_tasks.order_id', '=', 'orders.id')
                ->join('customers', 'orders.customer_id', '=', 'customers.id')
                ->where('delivery_tasks.partner_id', $partnerId)
                ->select(
                    'delivery_tasks.*',
                    'orders.order_number',
                    'customers.name as customer_name',
                    'customers.phone as customer_phone'
                )
                ->orderBy('delivery_tasks.created_at', 'desc')
                ->limit(5)
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'today_tasks' => $todayTasks,
                    'today_earnings' => (float) $todayEarnings,
                    'total_tasks' => $totalTasks,
                    'total_earnings' => (float) $totalEarnings,
                    'active_tasks' => $activeTasks,
                    'average_rating' => round($avgRating, 2),
                    'recent_tasks' => $recentTasks
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
    
    /**
     * Get profile
     */
    public function getProfile(Request $request)
    {
        try {
            $partnerId = $request->user()->id;
            
            $partner = DB::table('delivery_partners')->find($partnerId);
            
            if (!$partner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery partner not found'
                ], 404);
            }
            
            // Get today's earnings
            $todayEarnings = DB::table('delivery_earnings')
                ->where('partner_id', $partnerId)
                ->whereDate('created_at', today())
                ->where('payment_status', 'paid')
                ->sum('total_amount');
            
            // Get total delivered
            $totalDelivered = DB::table('delivery_tasks')
                ->where('partner_id', $partnerId)
                ->where('status', 'delivered')
                ->count();
            
            // Get rating
            $rating = DB::table('delivery_ratings')
                ->where('partner_id', $partnerId)
                ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as total_ratings')
                ->first();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'profile' => $partner,
                    'stats' => [
                        'today_earnings' => (float) $todayEarnings,
                        'total_delivered' => $totalDelivered,
                        'avg_rating' => round($rating->avg_rating ?? 0, 2),
                        'total_ratings' => $rating->total_ratings ?? 0
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
            'vehicle_type' => 'in:bike,car,scooter,van',
            'vehicle_number' => 'string|max:50',
            'profile_image' => 'nullable|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $partnerId = $request->user()->id;
            
            $updateData = [];
            if ($request->has('name')) $updateData['name'] = $request->name;
            if ($request->has('vehicle_type')) $updateData['vehicle_type'] = $request->vehicle_type;
            if ($request->has('vehicle_number')) $updateData['vehicle_number'] = $request->vehicle_number;
            if ($request->has('profile_image')) $updateData['profile_image'] = $request->profile_image;
            
            $updateData['updated_at'] = now();
            
            DB::table('delivery_partners')->where('id', $partnerId)->update($updateData);
            
            $updatedPartner = DB::table('delivery_partners')->find($partnerId);
            
            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $updatedPartner
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== AVAILABILITY ====================
    
    /**
     * Update availability status
     */
    public function updateAvailability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'is_online' => 'required|boolean',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $partnerId = $request->user()->id;
            
            $updateData = [
                'is_online' => $request->is_online,
                'last_active_at' => now(),
                'updated_at' => now()
            ];
            
            if ($request->has('lat') && $request->has('lng')) {
                $updateData['current_lat'] = $request->lat;
                $updateData['current_lng'] = $request->lng;
            }
            
            DB::table('delivery_partners')->where('id', $partnerId)->update($updateData);
            
            // Log location if provided
            if ($request->has('lat') && $request->has('lng')) {
                DB::table('delivery_locations')->insert([
                    'partner_id' => $partnerId,
                    'lat' => $request->lat,
                    'lng' => $request->lng,
                    'created_at' => now()
                ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => $request->is_online ? 'You are now online' : 'You are now offline'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update availability',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update location
     */
    public function updateLocation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'speed' => 'nullable|numeric|min:0',
            'battery_level' => 'nullable|integer|min:0|max:100',
            'task_id' => 'nullable|exists:delivery_tasks,id'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $partnerId = $request->user()->id;
            
            // Update current location
            DB::table('delivery_partners')->where('id', $partnerId)->update([
                'current_lat' => $request->lat,
                'current_lng' => $request->lng,
                'last_active_at' => now(),
                'updated_at' => now()
            ]);
            
            // Log location
            DB::table('delivery_locations')->insert([
                'partner_id' => $partnerId,
                'task_id' => $request->task_id,
                'lat' => $request->lat,
                'lng' => $request->lng,
                'speed' => $request->speed,
                'battery_level' => $request->battery_level,
                'created_at' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Location updated'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update location',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== TASKS MANAGEMENT ====================
    
    /**
     * Get available tasks
     */
    public function getAvailableTasks(Request $request)
    {
        try {
            $partnerId = $request->user()->id;
            
            // Check if partner is online
            $partner = DB::table('delivery_partners')
                ->where('id', $partnerId)
                ->where('is_online', true)
                ->first();
            
            if (!$partner) {
                return response()->json([
                    'success' => false,
                    'message' => 'You need to be online to see available tasks'
                ], 400);
            }
            
            // Get available tasks (not assigned to anyone yet)
            $tasks = DB::table('delivery_tasks')
                ->join('orders', 'delivery_tasks.order_id', '=', 'orders.id')
                ->join('vendors', 'orders.vendor_id', '=', 'vendors.id')
                ->where('delivery_tasks.status', 'assigned')
                ->whereNull('delivery_tasks.partner_id')
                ->select(
                    'delivery_tasks.*',
                    'orders.order_number',
                    'orders.final_amount',
                    'vendors.business_name as vendor_name',
                    'vendors.business_address as vendor_address'
                )
                ->orderBy('delivery_tasks.created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $tasks
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch available tasks',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get my tasks
     */
    public function getMyTasks(Request $request)
    {
        try {
            $partnerId = $request->user()->id;
            
            $query = DB::table('delivery_tasks')
                ->join('orders', 'delivery_tasks.order_id', '=', 'orders.id')
                ->join('vendors', 'orders.vendor_id', '=', 'vendors.id')
                ->join('customers', 'orders.customer_id', '=', 'customers.id')
                ->where('delivery_tasks.partner_id', $partnerId)
                ->select(
                    'delivery_tasks.*',
                    'orders.order_number',
                    'orders.final_amount',
                    'vendors.business_name as vendor_name',
                    'customers.name as customer_name',
                    'customers.phone as customer_phone'
                );
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('delivery_tasks.status', $request->status);
            }
            
            // Filter by date
            if ($request->has('date')) {
                $query->whereDate('delivery_tasks.created_at', $request->date);
            }
            
            $tasks = $query->orderBy('delivery_tasks.created_at', 'desc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $tasks
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tasks',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Accept a task
     */
    public function acceptTask(Request $request, $taskId)
    {
        try {
            $partnerId = $request->user()->id;
            
            // Check if partner is online
            $partner = DB::table('delivery_partners')
                ->where('id', $partnerId)
                ->where('is_online', true)
                ->first();
            
            if (!$partner) {
                return response()->json([
                    'success' => false,
                    'message' => 'You need to be online to accept tasks'
                ], 400);
            }
            
            // Check task availability
            $task = DB::table('delivery_tasks')
                ->where('id', $taskId)
                ->where('status', 'assigned')
                ->whereNull('partner_id')
                ->first();
            
            if (!$task) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task not available or already taken'
                ], 400);
            }
            
            // Update task
            DB::table('delivery_tasks')->where('id', $taskId)->update([
                'partner_id' => $partnerId,
                'status' => 'accepted',
                'started_at' => now(),
                'updated_at' => now()
            ]);
            
            // Update order status
            DB::table('orders')->where('id', $task->order_id)->update([
                'delivery_partner_id' => $partnerId,
                'order_status' => 'picked_up',
                'updated_at' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Task accepted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to accept task',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update task status
     */
    public function updateTaskStatus(Request $request, $taskId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:picked_up,on_the_way,delivered,cancelled,failed',
            'notes' => 'nullable|string',
            'actual_distance' => 'nullable|numeric|min:0',
            'actual_time' => 'nullable|integer|min:0'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $partnerId = $request->user()->id;
            
            // Check if task belongs to partner
            $task = DB::table('delivery_tasks')
                ->where('id', $taskId)
                ->where('partner_id', $partnerId)
                ->first();
            
            if (!$task) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task not found'
                ], 404);
            }
            
            $updateData = [
                'status' => $request->status,
                'updated_at' => now()
            ];
            
            if ($request->has('notes')) $updateData['notes'] = $request->notes;
            if ($request->has('actual_distance')) $updateData['actual_distance'] = $request->actual_distance;
            if ($request->has('actual_time')) $updateData['actual_time'] = $request->actual_time;
            
            if ($request->status === 'delivered' || $request->status === 'cancelled' || $request->status === 'failed') {
                $updateData['completed_at'] = now();
            }
            
            DB::table('delivery_tasks')
                ->where('id', $taskId)
                ->where('partner_id', $partnerId)
                ->update($updateData);
            
            // Update order status if delivered
            if ($request->status === 'delivered') {
                DB::table('orders')->where('id', $task->order_id)->update([
                    'order_status' => 'delivered',
                    'actual_delivery_time' => now(),
                    'updated_at' => now()
                ]);
                
                // Create earning record
                $this->createEarningRecord($taskId, $partnerId);
            }
            
            return response()->json([
                'success' => true,
                'message' => "Task status updated to {$request->status}"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get task details
     */
    public function getTaskDetails(Request $request, $taskId)
    {
        try {
            $partnerId = $request->user()->id;
            
            $task = DB::table('delivery_tasks')
                ->join('orders', 'delivery_tasks.order_id', '=', 'orders.id')
                ->join('vendors', 'orders.vendor_id', '=', 'vendors.id')
                ->join('customers', 'orders.customer_id', '=', 'customers.id')
                ->where('delivery_tasks.id', $taskId)
                ->where('delivery_tasks.partner_id', $partnerId)
                ->select(
                    'delivery_tasks.*',
                    'orders.order_number',
                    'orders.final_amount',
                    'orders.customer_notes',
                    'vendors.business_name as vendor_name',
                    'vendors.business_address as vendor_address',
                    'vendors.phone as vendor_phone',
                    'customers.name as customer_name',
                    'customers.phone as customer_phone'
                )
                ->first();
            
            if (!$task) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task not found'
                ], 404);
            }
            
            // Get order items
            $orderItems = DB::table('order_items')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->where('order_items.order_id', $task->order_id)
                ->select('order_items.*', 'products.name as product_name')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'task' => $task,
                    'items' => $orderItems
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch task details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== EARNINGS ====================
    
    /**
     * Get earnings
     */
    public function getEarnings(Request $request)
    {
        try {
            $partnerId = $request->user()->id;
            
            $query = DB::table('delivery_earnings')
                ->join('delivery_tasks', 'delivery_earnings.task_id', '=', 'delivery_tasks.id')
                ->join('orders', 'delivery_tasks.order_id', '=', 'orders.id')
                ->where('delivery_earnings.partner_id', $partnerId)
                ->select(
                    'delivery_earnings.*',
                    'delivery_tasks.status as task_status',
                    'orders.order_number'
                );
            
            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('delivery_earnings.created_at', [
                    $request->start_date,
                    $request->end_date
                ]);
            }
            
            // Filter by payment status
            if ($request->has('payment_status')) {
                $query->where('delivery_earnings.payment_status', $request->payment_status);
            }
            
            $earnings = $query->orderBy('delivery_earnings.created_at', 'desc')->get();
            
            // Summary
            $totalEarnings = $earnings->where('payment_status', 'paid')->sum('total_amount');
            $pendingEarnings = $earnings->where('payment_status', 'pending')->sum('total_amount');
            $todayEarnings = $earnings->where('payment_status', 'paid')
                ->filter(function($earning) {
                    return $earning->created_at >= now()->startOfDay();
                })
                ->sum('total_amount');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'earnings' => $earnings,
                    'summary' => [
                        'total' => (float) $totalEarnings,
                        'pending' => (float) $pendingEarnings,
                        'today' => (float) $todayEarnings
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
    
    /**
     * Get earnings summary
     */
    public function getEarningsSummary(Request $request)
    {
        try {
            $partnerId = $request->user()->id;
            
            // Weekly earnings
            $weeklyEarnings = DB::table('delivery_earnings')
                ->where('partner_id', $partnerId)
                ->where('payment_status', 'paid')
                ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->selectRaw('DAYNAME(created_at) as day, SUM(total_amount) as amount')
                ->groupBy(DB::raw('DAYNAME(created_at)'))
                ->get();
            
            // Monthly summary
            $monthlySummary = DB::table('delivery_earnings')
                ->where('partner_id', $partnerId)
                ->where('payment_status', 'paid')
                ->whereYear('created_at', now()->year)
                ->selectRaw('MONTHNAME(created_at) as month, SUM(total_amount) as amount')
                ->groupBy(DB::raw('MONTHNAME(created_at)'))
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'weekly' => $weeklyEarnings,
                    'monthly' => $monthlySummary
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch earnings summary',
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
            $partnerId = $request->user()->id;
            
            $query = DB::table('delivery_notifications')
                ->where('partner_id', $partnerId);
            
            // Filter by read status
            if ($request->has('is_read')) {
                $query->where('is_read', $request->is_read);
            }
            
            $notifications = $query->orderBy('created_at', 'desc')->get();
            
            // Mark as read if requested
            if ($request->has('mark_read') && $request->mark_read) {
                DB::table('delivery_notifications')
                    ->where('partner_id', $partnerId)
                    ->where('is_read', false)
                    ->update(['is_read' => true]);
            }
            
            // Unread count
            $unreadCount = DB::table('delivery_notifications')
                ->where('partner_id', $partnerId)
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
    
    // ==================== HELPER METHODS ====================
    
    /**
     * Create earning record for completed task
     */
    private function createEarningRecord($taskId, $partnerId)
    {
        try {
            $task = DB::table('delivery_tasks')->find($taskId);
            
            if (!$task) return;
            
            // Calculate earnings (simple logic)
            $baseFare = 20.00; // Base fare
            $distanceFare = $task->actual_distance ? $task->actual_distance * 5 : 0; // $5 per km
            $timeFare = $task->actual_time ? ($task->actual_time / 60) * 10 : 0; // $10 per hour
            
            $totalAmount = $baseFare + $distanceFare + $timeFare;
            
            DB::table('delivery_earnings')->insert([
                'partner_id' => $partnerId,
                'task_id' => $taskId,
                'base_fare' => $baseFare,
                'distance_fare' => $distanceFare,
                'time_fare' => $timeFare,
                'total_amount' => $totalAmount,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the main operation
            \Log::error('Failed to create earning record: ' . $e->getMessage());
        }
    }
}