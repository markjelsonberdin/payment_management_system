<?php
/**
 * ConvenienceFeeService
 * 
 * Calculates processing fees based on the selected payment channel and fee policy.
 * 
 * Polices:
 * - pass_to_student: The student pays the tuition + fee, ensuring the school receives the exact tuition amount.
 * - absorb_by_school: The student pays only the tuition, and the school absorbs the fee.
 */

class ConvenienceFeeService {
    
    // Rates based on PayMongo pricing.
    // NOTE: If PayMongo applies 12% VAT on top of these fees in your actual payouts, 
    // you may need to update these rates to (rate * 1.12).
    private const RATES = [
        'qrph'  => ['percentage' => 0.0134,  'fixed' => 0.00],
        'gcash' => ['percentage' => 0.0223,  'fixed' => 0.00],
        'maya'  => ['percentage' => 0.0179,  'fixed' => 0.00],
        'card'  => ['percentage' => 0.03125, 'fixed' => 13.39]
    ];

    /**
     * Calculate fees for a specific amount and channel
     * 
     * @param float $amount The base amount to be paid (tuition/balance)
     * @param string $channel The payment channel (gcash, maya, qrph, card)
     * @param string $feePolicy The policy (pass_to_student, absorb_by_school)
     * @return array
     */
    public function calculateFee(float $amount, string $channel, string $feePolicy): array {
        if (!isset(self::RATES[$channel])) {
            throw new InvalidArgumentException("Invalid payment channel for fee calculation: " . $channel);
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException("Amount must be greater than zero.");
        }

        $rate = self::RATES[$channel];
        $pct = $rate['percentage'];
        $fixed = $rate['fixed'];

        if ($feePolicy === 'pass_to_student') {
            // Gross amount calculation so the school receives EXACTLY $amount.
            // Formula: Gross = (Amount + Fixed) / (1 - Pct)
            // Processing Fee = Gross - Amount
            $checkoutTotal = ($amount + $fixed) / (1 - $pct);
            $checkoutTotal = round($checkoutTotal, 2);
            $processingFee = round($checkoutTotal - $amount, 2);
            
            return [
                'amount_applied' => $amount,
                'processing_fee' => $processingFee,
                'checkout_total' => $checkoutTotal,
                'policy'         => $feePolicy
            ];
        } else {
            // Absorb by school
            // The student pays EXACTLY the tuition amount.
            // The school absorbs the fee (Gross = Amount).
            $processingFee = round(($amount * $pct) + $fixed, 2);
            
            return [
                'amount_applied' => $amount,
                'processing_fee' => $processingFee,
                'checkout_total' => $amount, // Student only pays the base amount
                'policy'         => $feePolicy
            ];
        }
    }
}
