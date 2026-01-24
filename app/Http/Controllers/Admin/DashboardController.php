<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function index(Request $request)
    {
        try {
            // Get counts
            $totalOrders = DB::table('orders')->count();
            $totalRevenue = DB::table('orders')->where('payment_status', 'paid')->sum('final_amount');
            $totalCustomers = DB::table('customers')->count();
            $totalVendors = DB::table('vendors')->where('status', 'active')->count();
            $totalDeliveryPartners = DB::table('delivery_partners')->where('status', 'approved')->count();
            
            // Get recent orders
            $recentOrders = DB::table('orders')
                ->join('customers', 'orders.customer_id', '=', 'customers.id')
                ->join('vendors', 'orders.vendor_id', '=', 'vendors.id')
                ->select(
                    'orders.id',
                    'orders.order_number',
                    'orders.order_status',
                    'orders.payment_status',
                    'orders.final_amount',
                    'orders.created_at',
                    'customers.name as customer_name',
                    'vendors.business_name as vendor_name'
                )
                ->orderBy('orders.created_at', 'desc')
                ->limit(10)
                ->get();
            
            // Get order status counts
            $orderStatusCounts = DB::table('orders')
                ->select('order_status', DB::raw('COUNT(*) as count'))
                ->groupBy('order_status')
                ->get()
                ->pluck('count', 'order_status');
            
            // Get today's stats
            $todayOrders = DB::table('orders')
                ->whereDate('created_at', today())
                ->count();
            
            $todayRevenue = DB::table('orders')
                ->whereDate('created_at', today())
                ->where('payment_status', 'paid')
                ->sum('final_amount');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'counts' => [
                        'total_orders' => $totalOrders,
                        'total_revenue' => (float) $totalRevenue,
                        'total_customers' => $totalCustomers,
                        'total_vendors' => $totalVendors,
                        'total_delivery_partners' => $totalDeliveryPartners,
                        'today_orders' => $todayOrders,
                        'today_revenue' => (float) $todayRevenue
                    ],
                    'order_status_counts' => $orderStatusCounts,
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
}