<?php

namespace App\Http\Controllers\UsersideControllers\OrdersController;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Orders;
use App\Models\OrderItems;
use App\Models\Carts;
use App\Models\Carts_Item;
use App\Models\StockOut;
use App\Models\Products;
use App\Models\Inventory;
use App\Models\StockIn;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlaceOrderCont extends Controller
{
    public function placeOrder(Request $request)
    {
        $validated = $request->validate([
            'payment_method' => 'required|string',
            'fulfillment_method' => 'required|string',
            'campus' => 'nullable|string',
            'cart_items' => 'required|array'
        ]);

        try {
            // Get user
            $userId = Auth::id();

            if (empty($validated['cart_items'])) {
                return response()->json([
                    'message' => 'No items to order'
                ], 400);
            }

            // Validate stock availability for all items before creating order
            foreach ($validated['cart_items'] as $cartItem) {
                $inventoryItem = Inventory::where('product_id', $cartItem['product_id'])
                    ->where('variant', $cartItem['variant'] ?? '')
                    ->first();

                $availableStock = $inventoryItem ? $inventoryItem->quantity : 0;

                // Fallback to product_stock if inventory doesn't have exact variant
                if ($availableStock <= 0) {
                    $product = Products::where('product_id', $cartItem['product_id'])->first();
                    $availableStock = $product ? $product->product_stock : 0;
                }

                if ($availableStock < intval($cartItem['quantity'])) {
                    return response()->json([
                        'message' => 'Insufficient stock for product',
                        'product_id' => $cartItem['product_id'],
                        'requested' => intval($cartItem['quantity']),
                        'available' => $availableStock
                    ], 400);
                }
            }

            // Create order
            $order = Orders::create([
                'user_id' => $userId,
                'status' => 'Pending',
                'payment_method' => $validated['payment_method'],
                'fulfillment_method' => $validated['fulfillment_method'],
                'campus' => $validated['campus'],
                'order_date' => now(),
            ]);

            // Create order items from the provided cart items
            $total = 0;
            foreach ($validated['cart_items'] as $cartItem) {
                $subtotal = floatval($cartItem['price']) * intval($cartItem['quantity']);
                $total += $subtotal;

                OrderItems::create([
                    'order_id' => $order->order_id,
                    'product_id' => $cartItem['product_id'],
                    'quantity' => $cartItem['quantity'],
                    'price' => $cartItem['price'],
                    'variant' => $cartItem['variant'],
                    'subtotal' => $subtotal,
                ]);
            }

            // Remove only the ordered items from the user's cart
            $cart = Carts::where('user_id', $userId)->first();
            if ($cart) {
                // Prefer deleting by cart_item_id if provided by the client
                $cartItemIds = [];
                foreach ($validated['cart_items'] as $ci) {
                    if (isset($ci['cart_item_id'])) {
                        $cartItemIds[] = $ci['cart_item_id'];
                    }
                }

                if (!empty($cartItemIds)) {
                    Carts_Item::whereIn('cart_item_id', $cartItemIds)->delete();
                } else {
                    // Fallback: delete matching product_id + variant entries from this cart
                    foreach ($validated['cart_items'] as $ci) {
                        Carts_Item::where('cart_id', $cart->cart_id)
                            ->where('product_id', $ci['product_id'])
                            ->where('variant', $ci['variant'] ?? '')
                            ->delete();
                    }
                }
            }

            return response()->json([
                'message' => 'Order placed successfully',
                'orderId' => $order->order_id
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error placing order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Get orders for the authenticated user

    public function getUserOrders()
    {
        try {
            $userId = Auth::id();
            
            if (!$userId) {
                return response()->json([
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            $orders = Orders::where('user_id', $userId)
                ->with('orderItems.product')
                ->orderBy('created_at', 'desc')
                ->get();
            
            $formattedOrders = $orders->map(function ($order) {
                $orderItems = $order->orderItems ?? [];
                $orderTotal = 0;
                
                foreach ($orderItems as $item) {
                    $orderTotal += ($item->subtotal ?? 0);
                }
                
                return [
                    'order_id' => $order->order_id ?? null,
                    'order_status' => $order->status ?? 'Pending',
                    'order_total' => $orderTotal,
                    'fulfillment_method' => $order->fulfillment_method ?? 'N/A',
                    'campus' => $order->campus ?? null,
                    'receipt_form' => $order->receipt_form ?? null,
                    'receipt_form_url' => $order->receipt_form_url,
                    'created_at' => $order->created_at,
                    'order_items' => $orderItems->map(function ($item) {
                        return [
                            'quantity' => $item->quantity ?? 0,
                            'price' => $item->price ?? 0,
                            'variant' => $item->variant ?? 'N/A',
                            'subtotal' => $item->subtotal ?? 0,
                            'product' => $item->product ? [
                                'product_id' => $item->product->product_id ?? null,
                                'product_name' => $item->product->product_name ?? 'Product',
                                'product_image' => $item->product->product_image ?? null,
                                'product_description' => $item->product->product_description ?? null,
                            ] : null
                        ];
                    })->toArray()
                ];
            });

            return response()->json($formattedOrders, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function uploadReceipt($orderId)
    {
        try {
            $userId = Auth::id();
            
            if (!$userId) {
                return response()->json([
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Find order
            $order = Orders::where('order_id', $orderId)
                ->where('user_id', $userId)
                ->first();

            if (!$order) {
                return response()->json([
                    'message' => 'Order not found'
                ], 404);
            }

            // Validate file
            if (!request()->hasFile('receipt_form')) {
                return response()->json([
                    'message' => 'No file provided'
                ], 400);
            }

            $file = request()->file('receipt_form');
            
            // Validate file type
            $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
            if (!in_array($file->getMimeType(), $allowedMimes)) {
                return response()->json([
                    'message' => 'Invalid file type. Only JPG, PNG, and PDF are allowed'
                ], 400);
            }

            // Store file in storage/app/receipts directory
            $fileName = 'receipt_' . $orderId . '_' . time() . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('receipts', $fileName, 'public');

            // Update order with receipt_form path and status
            // If order was Cancelled, change it to Pending (To-Pay)
            $updateData = [
                'receipt_form' => $filePath
            ];
            
            if (strtolower($order->status) === 'cancelled') {
                $updateData['status'] = 'Pending';
            }
            
            $order->update($updateData);
            
            // Refresh the model to ensure receipt_form is updated
            $order->refresh();
            
            Log::info("Order {$orderId} updated with receipt_form: " . $order->receipt_form);

            return response()->json([
                'message' => 'Receipt uploaded successfully',
                'file_path' => $filePath,
                'new_status' => $order->status,
                'receipt_form' => $order->receipt_form ?? $filePath,
                'receipt_form_url' => $order->receipt_form_url
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error uploading receipt',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAllOrders()
    {
        if (!Auth::check() || Auth::user()->role !== 'Admin') {
            abort(403, 'Only admins can view all orders');
        }

        try {
            $orders = Orders::with(['orderItems.product', 'user'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            $formattedOrders = $orders->map(function ($order) {
                $orderItems = $order->orderItems ?? [];
                $orderTotal = 0;
                
                foreach ($orderItems as $item) {
                    $orderTotal += ($item->subtotal ?? 0);
                }
                
                return [
                    'order_id' => $order->order_id ?? null,
                    'order_status' => $order->status ?? 'Pending',
                    'order_total' => $orderTotal,
                    'receipt_form' => $order->receipt_form ?? null,
                    'receipt_form_url' => $order->receipt_form_url,
                    'campus' => $order->campus ?? null,
                    'created_at' => $order->created_at,
                    'user_id' => $order->user_id,
                    'user_fullname' => $order->user?->user_fullname ?? 'Customer',
                    'order_items' => $orderItems->map(function ($item) {
                        return [
                            'quantity' => $item->quantity ?? 0,
                            'price' => $item->price ?? 0,
                            'variant' => $item->variant ?? 'N/A',
                            'subtotal' => $item->subtotal ?? 0,
                            'product' => $item->product ? [
                                'product_id' => $item->product->product_id ?? null,
                                'product_name' => $item->product->product_name ?? 'Product',
                                'product_image' => $item->product->product_image ?? null,
                            ] : null
                        ];
                    })->toArray()
                ];
            });

            return response()->json($formattedOrders, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateOrderStatus($orderId, Request $request)
    {
        try {
            Log::info('updateOrderStatus called with orderId: ' . $orderId);
            Log::info('Status from request: ' . $request->input('status'));
            
            $order = Orders::where('order_id', $orderId)->first();
            
            Log::info('Order found: ' . ($order ? 'yes' : 'no'));
            
            if (!$order) {
                return response()->json(['message' => 'Order not found'], 404);
            }
            
            $status = $request->input('status');
            $newStatus = $status;
            
            // Get current user info before transaction
            $modifiedByUser = 'System';
            if (Auth::check()) {
                $user = Auth::user();
                if ($user && isset($user->user_fullname)) {
                    $modifiedByUser = $user->user_fullname;
                }
            }
            
            // If order is being completed, create stock-out records and deduct inventory
            // Check both 'completed' and 'Completed' (case-insensitive)
            $lowerStatus = strtolower($newStatus);
            $lowerCurrentStatus = strtolower($order->status);
            
            if ($lowerStatus === 'completed' && $lowerCurrentStatus !== 'completed') {
                // Validate that products exist
                $orderItems = OrderItems::where('order_id', $order->order_id)->get();
                
                foreach ($orderItems as $item) {
                    $product = Products::where('product_id', $item->product_id)->first();
                    
                    if (!$product) {
                        return response()->json([
                            'message' => 'Product not found',
                            'error' => 'Product ID ' . $item->product_id . ' does not exist'
                        ], 400);
                    }
                }
                
                DB::transaction(function () use ($order, $modifiedByUser) {
                    // Get all order items
                    $orderItems = OrderItems::where('order_id', $order->order_id)->get();
                    Log::info('Order ' . $order->order_id . ' has ' . $orderItems->count() . ' items');
                    
                    foreach ($orderItems as $item) {
                        Log::info('Processing item - Product ID: ' . $item->product_id . ', Quantity: ' . $item->quantity);
                        
                        // Create StockOut record for each item
                        StockOut::create([
                            'product_id' => $item->product_id,
                            'order_id' => $order->order_id,
                            'quantity' => $item->quantity,
                            'modified_by' => $modifiedByUser,
                            'reason' => 'order',
                            'date_time' => now()
                        ]);
                        
                        Log::info('StockOut created for product ' . $item->product_id);
                        
                        // Decrement product stock
                        $updated = Products::where('product_id', $item->product_id)
                            ->decrement('product_stock', $item->quantity);
                        
                        Log::info('Products updated: ' . $updated . ' rows affected');
                        
                        // Update inventory quantity
                        $invUpdated = Inventory::where('product_id', $item->product_id)
                            ->decrement('quantity', $item->quantity);
                        
                        Log::info('Inventory updated: ' . $invUpdated . ' rows affected');
                        
                        // Decrement stock_ins quantity
                        $stockInUpdated = StockIn::where('product_id', $item->product_id)
                            ->decrement('stock_qty', $item->quantity);
                        
                        Log::info('StockIn updated: ' . $stockInUpdated . ' rows affected');
                    }
                    
                    Log::info('Created stock-out records and decremented inventory for order: ' . $order->order_id);
                });
            }
            
            $order->status = $newStatus;
            $order->save();
            
            return response()->json([
                'message' => 'Order status updated successfully',
                'order' => [
                    'order_id' => $order->order_id,
                    'status' => $order->status,
                    'campus' => $order->campus
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error updating order status: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'message' => 'Error updating order status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // This function allows users to buy the same items from a completed order again by adding them back to the cart
    public function buyAgain($orderId)
    {
        try {
            Log::info('buyAgain called with orderId: ' . $orderId);
            
            $userId = Auth::id();
            
            if (!$userId) {
                return response()->json([
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Find the completed order
            $order = Orders::where('order_id', $orderId)
                ->where('user_id', $userId)
                ->first();

            if (!$order) {
                return response()->json([
                    'message' => 'Order not found'
                ], 404);
            }

            Log::info('Order found: ' . $orderId . ' with status: ' . $order->status);

            // Get all items from the order
            $orderItems = OrderItems::where('order_id', $orderId)->get();

            if ($orderItems->isEmpty()) {
                return response()->json([
                    'message' => 'No items in order'
                ], 400);
            }

            Log::info('Order has ' . $orderItems->count() . ' items');

            // Get or create user's cart
            $cart = Carts::firstOrCreate(['user_id' => $userId]);

            Log::info('Cart ID: ' . $cart->cart_id);

            $successCount = 0;
            $failedItems = [];

            // Add each item from order to cart
            foreach ($orderItems as $item) {
                try {
                    Log::info('Processing item - Product ID: ' . $item->product_id . ', Variant: ' . $item->variant . ', Quantity: ' . $item->quantity);

                    // Check inventory availability
                    $inventoryItem = Inventory::where('product_id', $item->product_id)
                        ->where('variant', $item->variant)
                        ->first();

                    $availableStock = $inventoryItem ? $inventoryItem->quantity : 0;

                    // Fallback to product_stock if inventory doesn't have the variant
                    if ($availableStock === 0) {
                        $product = Products::where('product_id', $item->product_id)->first();
                        $availableStock = $product ? $product->product_stock : 0;
                    }

                    Log::info('Available stock: ' . $availableStock . ' for product ' . $item->product_id);

                    // Validate stock is available
                    if ($availableStock <= 0) {
                        Log::warning('Product ' . $item->product_id . ' out of stock');
                        $failedItems[] = [
                            'product_id' => $item->product_id,
                            'product_name' => $item->product->product_name ?? 'Product',
                            'variant' => $item->variant,
                            'reason' => 'Out of Stock'
                        ];
                        continue;
                    }

                    if ($item->quantity > $availableStock) {
                        Log::warning('Insufficient stock for product ' . $item->product_id);
                        $failedItems[] = [
                            'product_id' => $item->product_id,
                            'product_name' => $item->product->product_name ?? 'Product',
                            'variant' => $item->variant,
                            'reason' => 'Insufficient Stock Available',
                            'requested' => $item->quantity,
                            'available' => $availableStock
                        ];
                        continue;
                    }

                    // Check if item already exists in cart
                    $existingItem = Carts_Item::where('cart_id', $cart->cart_id)
                        ->where('product_id', $item->product_id)
                        ->where('variant', $item->variant)
                        ->first();

                    if ($existingItem) {
                        $newQuantity = $existingItem->quantity + $item->quantity;

                        // Check if total quantity exceeds available stock
                        if ($newQuantity > $availableStock) {
                            Log::warning('Total quantity exceeds stock for product ' . $item->product_id);
                            $failedItems[] = [
                                'product_id' => $item->product_id,
                                'product_name' => $item->product->product_name ?? 'Product',
                                'variant' => $item->variant,
                                'reason' => 'Total quantity exceeds available stock',
                                'current_in_cart' => $existingItem->quantity,
                                'requested' => $item->quantity,
                                'total_requested' => $newQuantity,
                                'available' => $availableStock
                            ];
                            continue;
                        }

                        $existingItem->update(['quantity' => $newQuantity]);
                        Log::info('Item quantity updated in cart to ' . $newQuantity);
                    } else {
                        // Create new cart item
                        Carts_Item::create([
                            'cart_id' => $cart->cart_id,
                            'product_id' => $item->product_id,
                            'variant' => $item->variant,
                            'quantity' => $item->quantity,
                            'price' => $item->price
                        ]);
                        Log::info('New item added to cart');
                    }

                    $successCount++;

                } catch (\Exception $e) {
                    Log::error('Error processing item ' . $item->product_id . ': ' . $e->getMessage());
                    $failedItems[] = [
                        'product_id' => $item->product_id,
                        'reason' => 'Processing Error: ' . $e->getMessage()
                    ];
                }
            }

            Log::info('Buy Again completed. Success: ' . $successCount . ', Failed: ' . count($failedItems));

            // Determine response based on results
            if ($successCount === 0) {
                return response()->json([
                    'message' => 'All items are out of stock. Please check back later.',
                    'success_count' => 0,
                    'failed_items' => $failedItems
                ], 400);
            }

            if (count($failedItems) === 0) {
                return response()->json([
                    'message' => 'All items added to cart! Redirecting...',
                    'success_count' => $successCount,
                    'failed_items' => [],
                    'total_items' => $orderItems->count()
                ], 200);
            }

            return response()->json([
                'message' => $successCount . ' items added to cart. ' . count($failedItems) . ' items unavailable.',
                'success_count' => $successCount,
                'failed_items' => $failedItems,
                'total_items' => $orderItems->count()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error in buyAgain: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'message' => 'Error processing buy again request',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}