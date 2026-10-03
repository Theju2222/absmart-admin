<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnStatusList extends Model
{
    use HasFactory;

    public $timestamps = false;

    // New flow: pending -> accepted/rejected -> delivery boy assigned -> out for
    // pickup -> received from customer -> return to store -> refund completed.
    public static $rPending = 1;
    public static $rAccepted = 2;
    public static $rRejected = 3;
    public static $rDeliveryBoyAssigned = 4;
    public static $rOutForPickup = 5;
    public static $rReceivedFromCustomer = 6;
    public static $rReturnToStore = 7;
    public static $rRefundCompleted = 8;

    public static $requestPending = "Return Requested";
    public static $accepted = "Accepted";
    public static $requestRejected = "Request Rejected";
    public static $deliveryBoyAssigned = "Delivery Boy Assigned";
    public static $outForPickup = "Out for Pickup";
    public static $receivedFromCustomer = "Received from Customer";
    public static $returnToStore = "Return to Store";
    public static $refundCompleted = "Refund Completed";

    public static function getStatusName($status)
    {
        switch ((int) $status) {
            case self::$rPending:
                return self::$requestPending;
            case self::$rAccepted:
                return self::$accepted;
            case self::$rRejected:
                return self::$requestRejected;
            case self::$rDeliveryBoyAssigned:
                return self::$deliveryBoyAssigned;
            case self::$rOutForPickup:
                return self::$outForPickup;
            case self::$rReceivedFromCustomer:
                return self::$receivedFromCustomer;
            case self::$rReturnToStore:
                return self::$returnToStore;
            case self::$rRefundCompleted:
                return self::$refundCompleted;
            default:
                return "Unknown Status";
        }
    }

    public static function getAllStatuses()
    {
        return [
            self::$rPending              => self::$requestPending,
            self::$rAccepted             => self::$accepted,
            self::$rRejected             => self::$requestRejected,
            self::$rDeliveryBoyAssigned  => self::$deliveryBoyAssigned,
            self::$rOutForPickup         => self::$outForPickup,
            self::$rReceivedFromCustomer => self::$receivedFromCustomer,
            self::$rReturnToStore        => self::$returnToStore,
            self::$rRefundCompleted      => self::$refundCompleted,
        ];
    }

    public static function getDeliveryBoyStatuses()
    {
        return [
            self::$rOutForPickup         => self::$outForPickup,
            self::$rReceivedFromCustomer => self::$receivedFromCustomer,
            self::$rReturnToStore        => self::$returnToStore,
        ];
    }

    public static function getTranslationKey(int $statusId): string
    {
        $statusKeys = [
            self::$rPending              => 'return_requested',
            self::$rAccepted             => 'accepted',
            self::$rRejected             => 'rejected',
            self::$rDeliveryBoyAssigned  => 'delivery_boy_assigned',
            self::$rOutForPickup         => 'out_for_pickup',
            self::$rReceivedFromCustomer => 'received_from_customer',
            self::$rReturnToStore        => 'return_to_store',
            self::$rRefundCompleted      => 'refund_completed',
        ];

        return $statusKeys[$statusId] ?? '';
    }

    public static function getTranslatedName(int $statusId): string
    {
        $key = self::getTranslationKey($statusId);

        if (empty($key)) {
            return self::getStatusName($statusId);
        }

        $translated = __($key);

        // If __() returns the key itself (untranslated), fall back to the static name
        return ($translated !== $key) ? $translated : self::getStatusName($statusId);
    }
}
