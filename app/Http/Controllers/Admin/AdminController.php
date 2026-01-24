<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    // ==================== CATEGORY MANAGEMENT ====================
    
    /**
     * Get all categories
     */
    public function getCategories(Request $request)
    {
        try {
            $categories = DB::table('categories')
                ->orderBy('display_order')
                ->get();
            
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
     * Create new category
     */
    public function createCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'is_active' => 'boolean',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',

        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $imagePath = null;

            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store(
                    'categories',
                    'public'
                );
            }

            $slug = Str::slug($request->name) . '-' . time();
            
            $categoryId = DB::table('categories')->insertGetId([
                'name' => $request->name,
                'slug' => $slug,
                'description' => $request->description,
                'parent_id' => $request->parent_id,
                'is_active' => $request->is_active ?? true,
                'display_order' => $request->display_order ?? 0,
                'image' => $imagePath,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            $category = DB::table('categories')->find($categoryId);
            
            return response()->json([
                'success' => true,
                'message' => 'Category created successfully',
                'data' => $category
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create category',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update category
     */
    public function updateCategory(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'is_active' => 'boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $category = DB::table('categories')->find($id);
            
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }
            
            $updateData = [];
            if ($request->has('name')) {
                $updateData['name'] = $request->name;
                $updateData['slug'] = Str::slug($request->name) . '-' . time();
            }
            if ($request->has('description')) $updateData['description'] = $request->description;
            if ($request->has('parent_id')) $updateData['parent_id'] = $request->parent_id;
            if ($request->has('is_active')) $updateData['is_active'] = $request->is_active;
            if ($request->has('display_order')) $updateData['display_order'] = $request->display_order;
            
            $updateData['updated_at'] = now();
            
            DB::table('categories')->where('id', $id)->update($updateData);
            
            $updatedCategory = DB::table('categories')->find($id);
            
            return response()->json([
                'success' => true,
                'message' => 'Category updated successfully',
                'data' => $updatedCategory
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update category',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Delete category
     */
    public function deleteCategory($id)
    {
        try {
            $category = DB::table('categories')->find($id);
            
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }
            
            // Check if category has sub-categories
            $hasChildren = DB::table('categories')->where('parent_id', $id)->exists();
            if ($hasChildren) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete category with sub-categories'
                ], 400);
            }
            
            // Check if category has products
            $hasProducts = DB::table('products')->where('category_id', $id)->exists();
            if ($hasProducts) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete category with products'
                ], 400);
            }
            
            DB::table('categories')->where('id', $id)->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Category deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete category',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== VENDOR MANAGEMENT ====================
    
    /**
     * Get all vendors
     */
    public function getVendors(Request $request)
    {
        try {
            $query = DB::table('vendors');
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            // Filter by search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('business_name', 'like', "%{$search}%");
                });
            }
            
            $vendors = $query->orderBy('created_at', 'desc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $vendors
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch vendors',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Create new vendor
     */
    public function createVendor(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:vendors,email',
            'phone' => 'required|string|max:20',
            'business_name' => 'required|string|max:255',
            'business_address' => 'nullable|string',
            'commission_rate' => 'nullable|numeric|min:0|max:100'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $vendorId = DB::table('vendors')->insertGetId([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'business_name' => $request->business_name,
                'business_address' => $request->business_address,
                'commission_rate' => $request->commission_rate ?? 0.00,
                'status' => 'pending',
                'is_verified' => false,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            $vendor = DB::table('vendors')->find($vendorId);
            
            return response()->json([
                'success' => true,
                'message' => 'Vendor created successfully',
                'data' => $vendor
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update vendor
     */
    public function updateVendor(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'email' => 'email|unique:vendors,email,' . $id,
            'phone' => 'string|max:20',
            'business_name' => 'string|max:255',
            'business_address' => 'nullable|string',
            'commission_rate' => 'nullable|numeric|min:0|max:100'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $vendor = DB::table('vendors')->find($id);
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }
            
            $updateData = [];
            if ($request->has('name')) $updateData['name'] = $request->name;
            if ($request->has('email')) $updateData['email'] = $request->email;
            if ($request->has('phone')) $updateData['phone'] = $request->phone;
            if ($request->has('business_name')) $updateData['business_name'] = $request->business_name;
            if ($request->has('business_address')) $updateData['business_address'] = $request->business_address;
            if ($request->has('commission_rate')) $updateData['commission_rate'] = $request->commission_rate;
            
            $updateData['updated_at'] = now();
            
            DB::table('vendors')->where('id', $id)->update($updateData);
            
            $updatedVendor = DB::table('vendors')->find($id);
            
            return response()->json([
                'success' => true,
                'message' => 'Vendor updated successfully',
                'data' => $updatedVendor
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update vendor status (activate/suspend)
     */
    public function updateVendorStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,suspended,pending'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $vendor = DB::table('vendors')->find($id);
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }
            
            DB::table('vendors')->where('id', $id)->update([
                'status' => $request->status,
                'updated_at' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Vendor status updated to {$request->status}"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update vendor status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getCustomers(Request $request)
    {
        try {
            $query = DB::table('customers');
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            // Filter by search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }
            
            $customers = $query->orderBy('created_at', 'desc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $customers
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update customer status
     */
    public function updateCustomerStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,inactive,blocked'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $customer = DB::table('customers')->find($id);
            
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }
            
            DB::table('customers')->where('id', $id)->update([
                'status' => $request->status,
                'updated_at' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Customer status updated to {$request->status}"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== DELIVERY PARTNER MANAGEMENT ====================
    
    /**
     * Get all delivery partners
     */
    public function getDeliveryPartners(Request $request)
    {
        try {
            $query = DB::table('delivery_partners');
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            // Filter by vehicle type
            if ($request->has('vehicle_type')) {
                $query->where('vehicle_type', $request->vehicle_type);
            }
            
            // Filter by search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('vehicle_number', 'like', "%{$search}%");
                });
            }
            
            $partners = $query->orderBy('created_at', 'desc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $partners
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch delivery partners',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Verify delivery partner documents
     */
    public function verifyDeliveryPartner(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:approved,rejected',
            'notes' => 'nullable|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $partner = DB::table('delivery_partners')->find($id);
            
            if (!$partner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery partner not found'
                ], 404);
            }
            
            DB::table('delivery_partners')->where('id', $id)->update([
                'status' => $request->status,
                'updated_at' => now()
            ]);
            
            // Log verification action
            DB::table('partner_verifications')->insert([
                'delivery_partner_id' => $id,
                'admin_id' => $request->user()->id,
                'status' => $request->status,
                'notes' => $request->notes,
                'verified_at' => now(),
                'created_at' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Delivery partner {$request->status} successfully"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify delivery partner',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update delivery partner status
     */
    public function updateDeliveryPartnerStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:approved,suspended,pending,rejected'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $partner = DB::table('delivery_partners')->find($id);
            
            if (!$partner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery partner not found'
                ], 404);
            }
            
            DB::table('delivery_partners')->where('id', $id)->update([
                'status' => $request->status,
                'updated_at' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Delivery partner status updated to {$request->status}"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update delivery partner status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // ==================== ORDER MANAGEMENT ====================
    
    /**
     * Get all orders with filters
     */
    public function getOrders(Request $request)
    {
        try {
            $query = DB::table('orders')
                ->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
                ->leftJoin('vendors', 'orders.vendor_id', '=', 'vendors.id')
                ->leftJoin('delivery_partners', 'orders.delivery_partner_id', '=', 'delivery_partners.id')
                ->select(
                    'orders.*',
                    'customers.name as customer_name',
                    'customers.phone as customer_phone',
                    'vendors.business_name as vendor_name',
                    'delivery_partners.name as delivery_partner_name'
                );
            
            // Filter by order status
            if ($request->has('status')) {
                $query->where('orders.order_status', $request->status);
            }
            
            // Filter by payment status
            if ($request->has('payment_status')) {
                $query->where('orders.payment_status', $request->payment_status);
            }
            
            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('orders.created_at', [
                    $request->start_date,
                    $request->end_date
                ]);
            }
            
            // Filter by search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('orders.order_number', 'like', "%{$search}%")
                      ->orWhere('customers.name', 'like', "%{$search}%")
                      ->orWhere('customers.phone', 'like', "%{$search}%");
                });
            }
            
            // Pagination
            $perPage = $request->per_page ?? 20;
            $orders = $query->orderBy('orders.created_at', 'desc')->paginate($perPage);
            
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
    public function getOrderDetails($id)
    {
        try {
            // Get order
            $order = DB::table('orders')
                ->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
                ->leftJoin('vendors', 'orders.vendor_id', '=', 'vendors.id')
                ->leftJoin('delivery_partners', 'orders.delivery_partner_id', '=', 'delivery_partners.id')
                ->select(
                    'orders.*',
                    'customers.name as customer_name',
                    'customers.email as customer_email',
                    'customers.phone as customer_phone',
                    'vendors.business_name as vendor_name',
                    'vendors.phone as vendor_phone',
                    'delivery_partners.name as delivery_partner_name',
                    'delivery_partners.phone as delivery_partner_phone'
                )
                ->where('orders.id', $id)
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
                ->select('order_items.*', 'products.name as product_name', 'products.images')
                ->where('order_items.order_id', $id)
                ->get();
            
            // Get order status history
            $statusHistory = DB::table('order_status_history')
                ->where('order_id', $id)
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'order' => $order,
                    'items' => $orderItems,
                    'status_history' => $statusHistory
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
     * Update order status
     */
    public function updateOrderStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,confirmed,processing,ready,picked_up,on_the_way,delivered,cancelled,rejected',
            'notes' => 'nullable|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $order = DB::table('orders')->find($id);
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }
            
            // Update order status
            DB::table('orders')->where('id', $id)->update([
                'order_status' => $request->status,
                'updated_at' => now()
            ]);
            
            // Add to status history
            DB::table('order_status_history')->insert([
                'order_id' => $id,
                'status' => $request->status,
                'notes' => $request->notes,
                'changed_by' => 'admin',
                'changed_by_id' => $request->user()->id,
                'created_at' => now()
            ]);
            
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
    
    // ==================== SUPPORT TICKET MANAGEMENT ====================
    
    /**
     * Get all support tickets
     */
    public function getSupportTickets(Request $request)
    {
        try {
            $query = DB::table('support_tickets')
                ->join('customers', 'support_tickets.customer_id', '=', 'customers.id')
                ->leftJoin('orders', 'support_tickets.order_id', '=', 'orders.id')
                ->select(
                    'support_tickets.*',
                    'customers.name as customer_name',
                    'customers.email as customer_email',
                    'customers.phone as customer_phone',
                    'orders.order_number'
                );
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('support_tickets.status', $request->status);
            }
            
            // Filter by priority
            if ($request->has('priority')) {
                $query->where('support_tickets.priority', $request->priority);
            }
            
            // Filter by category
            if ($request->has('category')) {
                $query->where('support_tickets.category', $request->category);
            }
            
            // Filter by assigned to
            if ($request->has('assigned_to')) {
                $query->where('support_tickets.assigned_to', $request->assigned_to);
            }
            
            // Filter by search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('support_tickets.ticket_number', 'like', "%{$search}%")
                      ->orWhere('support_tickets.subject', 'like', "%{$search}%")
                      ->orWhere('customers.name', 'like', "%{$search}%")
                      ->orWhere('customers.phone', 'like', "%{$search}%");
                });
            }
            
            $tickets = $query->orderBy('support_tickets.created_at', 'desc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $tickets
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch support tickets',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get ticket details with replies
     */
    public function getTicketDetails($id)
    {
        try {
            $ticket = DB::table('support_tickets')
                ->join('customers', 'support_tickets.customer_id', '=', 'customers.id')
                ->leftJoin('orders', 'support_tickets.order_id', '=', 'orders.id')
                ->leftJoin('admins', 'support_tickets.assigned_to', '=', 'admins.id')
                ->select(
                    'support_tickets.*',
                    'customers.name as customer_name',
                    'customers.email as customer_email',
                    'customers.phone as customer_phone',
                    'orders.order_number',
                    'admins.name as assigned_admin_name'
                )
                ->where('support_tickets.id', $id)
                ->first();
            
            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket not found'
                ], 404);
            }
            
            // Get ticket replies
            $replies = DB::table('ticket_replies')
                ->where('ticket_id', $id)
                ->orderBy('created_at', 'asc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'ticket' => $ticket,
                    'replies' => $replies
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch ticket details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Reply to support ticket
     */
    public function replyToTicket(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string',
            'attachments' => 'nullable|array'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $ticket = DB::table('support_tickets')->find($id);
            
            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket not found'
                ], 404);
            }
            
            // Add reply
            DB::table('ticket_replies')->insert([
                'ticket_id' => $id,
                'replied_by' => 'admin',
                'replied_by_id' => $request->user()->id,
                'message' => $request->message,
                'attachments' => $request->attachments ? json_encode($request->attachments) : null,
                'created_at' => now()
            ]);
            
            // Update ticket as in progress if still open
            if ($ticket->status === 'open') {
                DB::table('support_tickets')->where('id', $id)->update([
                    'status' => 'in_progress',
                    'assigned_to' => $request->user()->id,
                    'updated_at' => now()
                ]);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Reply sent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send reply',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update ticket status
     */
    public function updateTicketStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:open,in_progress,resolved,closed',
            'assign_to' => 'nullable|exists:admins,id'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $ticket = DB::table('support_tickets')->find($id);
            
            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket not found'
                ], 404);
            }
            
            $updateData = [
                'status' => $request->status,
                'updated_at' => now()
            ];
            
            if ($request->has('assign_to')) {
                $updateData['assigned_to'] = $request->assign_to;
            }
            
            // Mark as resolved
            if ($request->status === 'resolved') {
                $updateData['resolved_at'] = now();
            }
            
            DB::table('support_tickets')->where('id', $id)->update($updateData);
            
            return response()->json([
                'success' => true,
                'message' => "Ticket status updated to {$request->status}"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update ticket status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}