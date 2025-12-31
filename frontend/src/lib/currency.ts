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
 * Convert USD to THB with short format for large numbers
 * @param usdAmount - Amount in USD
 * @returns Formatted string like "฿1.2k" or "฿35.00"
 */
export function formatTHBShort(
  usdAmount: number | string | null | undefined
): string {
  const usd = Number(usdAmount) || 0;
  const thb = usd * USD_TO_THB;

  if (thb >= 1000000) {
    return `฿${(thb / 1000000).toFixed(1)}M`;
  }
  if (thb >= 1000) {
    return `฿${(thb / 1000).toFixed(1)}k`;
  }
  return `฿${thb.toFixed(2)}`;
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
 * Get the current exchange rate
 */
export function getExchangeRate(): number {
  return USD_TO_THB;
}
