/**
 * Currency conversion utilities
 * Converts USD to Thai Baht (THB)
 */

// Fixed exchange rate: 1 USD = 34 THB
const USD_TO_THB = 34;

/**
 * Convert USD to THB and format with symbol
 * @param usdAmount - Amount in USD
 * @param decimals - Number of decimal places (default: 2)
 * @returns Formatted string like "฿35.00"
 */
export function formatTHB(
  usdAmount: number | string | null | undefined,
  decimals: number = 2
): string {
  const usd = Number(usdAmount) || 0;
  const thb = usd * USD_TO_THB;
  return `฿${thb.toFixed(decimals)}`;
}

/**
 * Convert USD to THB (raw number)
 * @param usdAmount - Amount in USD
 * @returns Amount in THB
 */
export function usdToTHB(usdAmount: number | string | null | undefined): number {
  const usd = Number(usdAmount) || 0;
  return usd * USD_TO_THB;
}

/**
 * Format THB-native amount (no USD conversion)
 * For amounts already in Thai Baht
 * @param amount - Amount in THB
 * @returns Formatted string like "฿1,500"
 */
export function formatBaht(amount: number): string {
  return new Intl.NumberFormat('th-TH', {
    style: 'currency',
    currency: 'THB',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(amount);
}
