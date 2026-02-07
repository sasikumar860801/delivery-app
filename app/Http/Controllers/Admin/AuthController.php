<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Vendor;
use App\Models\User;
use App\Models\Customer;
use App\Models\Delivery;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Admin Login
     */
    public function login(Request $request)
    {
        $pass=Hash::make('password123');
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('email', $request->email)
            ->where('status', 'active')
            ->first();
            

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        // Revoke old tokens (optional but recommended)
        $admin->tokens()->delete();

        // Create Sanctum token
        $token = $admin->createToken('admin-api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token'   => $token,
            'admin'   => [
                'id'    => $admin->id,
                'name'  => $admin->name,
                'email' => $admin->email,
            ],
        ]);
    }

  
public function vendor_login(Request $request)
{
    // 1️⃣ Validate request
    $validator = Validator::make($request->all(), [
        'mobile' => 'required|string',
        'otp'    => 'required|string|size:6',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors(),
        ], 422);
    }

    $mobile = $request->mobile;
    $otp    = $request->otp;

    // 2️⃣ Fetch OTP record from vendor_auth
    $vendorAuth = DB::table('vendor_auth')
        ->where('mobile', $mobile)
        ->first();

    if (! $vendorAuth) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid mobile number',
        ], 404);
    }

    // 3️⃣ Check OTP
    if ($vendorAuth->otp !== $otp) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid OTP',
        ], 400);
    }

    // 4️⃣ Check OTP expiry
    if (now()->gt($vendorAuth->otp_expires_at)) {
        return response()->json([
            'success' => false,
            'message' => 'OTP expired',
        ], 400);
    }

    // 5️⃣ Fetch vendor using DB facade
    $vendor = DB::table('vendors')
        ->where('id', $vendorAuth->vendor_id)
        ->first();

    if (! $vendor) {
        return response()->json([
            'success' => false,
            'message' => 'Vendor not found',
        ], 404);
    }

    // 6️⃣ Check vendor status
    if ($vendor->status !== 'active') {
        return response()->json([
            'success' => false,
            'message' => 'Your account is ' . $vendor->status,
        ], 403);
    }

    // 7️⃣ Use Vendor model **only for Sanctum token** (necessary)
    $vendorModel = Vendor::find($vendor->id);

    // Revoke old tokens
    $vendorModel->tokens()->delete();

    // Create new Sanctum token
    $token = $vendorModel->createToken('vendor-' . $vendor->id)->plainTextToken;

    // 8️⃣ Clear OTP after successful login
    DB::table('vendor_auth')
        ->where('mobile', $mobile)
        ->update([
            'otp' => null,
            'otp_expires_at' => null,
        ]);

    // 9️⃣ Return response
    return response()->json([
        'success' => true,
        'message' => 'Login successful',
        'token'   => $token,
        'token_type' => 'Bearer',
        'vendor'  => [
            'id'     => $vendor->id,
            'name'   => $vendor->name,
            'mobile' => $vendor->phone, // <-- use the correct column name
            'email'  => $vendor->email,
        ],
    ]);
}
    /**
     * Admin Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

     public function verifyOtp_delivery_partner(Request $request)
    {
      
     $validator = Validator::make($request->all(), [
            'mobile'    => 'required|string',
            'otp'       => 'required|string|size:6',
            'fcm_token' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        $mobile = $request->mobile;
        $otp    = $request->otp;
        $fcmToken = $request->fcm_token;

        try {
            // 2️⃣ Get delivery partner
            $partner = Delivery::where('phone', $mobile)->first();

            if (!$partner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            if ($partner->status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => "Account is {$partner->status}"
                ], 403);
            }

            // 3️⃣ Get OTP record
            $partnerAuth = DB::table('delivery_partner_auth')
                ->where('partner_id', $partner->id)
                ->first();

            if (!$partnerAuth) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid OTP'
                ], 400);
            }

            // 4️⃣ Check OTP and expiry
            if ($partnerAuth->otp !== $otp) {
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

            // 5️⃣ Revoke old tokens
            $partner->tokens()->delete();

            // 6️⃣ Create new Sanctum token
            $token = $partner->createToken('delivery-api-token')->plainTextToken;

            // 7️⃣ Clear OTP and update login info
            DB::table('delivery_partner_auth')
                ->where('id', $partnerAuth->id)
                ->update([
                    'otp' => null,
                    'otp_expires_at' => null,
                    'phone_verified_at' => now(),
                    'fcm_token' => $fcmToken,
                    'last_login_at' => now(),
                    'updated_at' => now()
                ]);

            // 8️⃣ Return response
            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'token' => $token,
                'token_type' => 'Bearer',
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

    public function verifyOtp_customer(Request $request)
{
    $validator = Validator::make($request->all(), [
        'mobile'     => 'required|string',
        'otp'        => 'required|string|size:6',
        'name'       => 'nullable|string|max:255',
        'email'      => 'nullable|email',
        'fcm_token'  => 'nullable|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors()
        ], 422);
    }

    try {
        $mobile = $request->mobile;
        $otp    = $request->otp;

        // ================= EXISTING CUSTOMER =================
        $customerAuth = DB::table('customer_auth')
            ->where('mobile', $mobile)
            ->first();

        if ($customerAuth) {

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

            $customer = Customer::find($customerAuth->customer_id);

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }

            if ($customer->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is ' . $customer->status
                ], 403);
            }

            // 🔑 DELETE OLD TOKENS (optional but good)
            $customer->tokens()->delete();

            // 🔑 CREATE PLAIN TEXT TOKEN
            $token = $customer->createToken('customer-api-token')->plainTextToken;

            // Clear OTP
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
                'token_type' => 'Bearer',
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'status' => $customer->status
                ]
            ]);
        }

        // ================= NEW CUSTOMER =================
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

        // Create customer
        $customerId = DB::table('customers')->insertGetId([
            'name' => $request->name ?? 'Customer',
            'email' => $request->email ?? $tempAuth->email,
            'phone' => $mobile,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('customer_auth')->insert([
            'customer_id' => $customerId,
            'mobile' => $mobile,
            'email' => $request->email ?? $tempAuth->email,
            'phone_verified_at' => now(),
            'fcm_token' => $request->fcm_token,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('temp_customer_auth')
            ->where('id', $tempAuth->id)
            ->delete();

        $customer = Customer::find($customerId);

        // 🔑 CREATE PLAIN TEXT TOKEN
        $token = $customer->createToken('customer-api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'token' => $token,
            'token_type' => 'Bearer',
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'status' => $customer->status
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to verify OTP'
        ], 500);
    }
}

}
