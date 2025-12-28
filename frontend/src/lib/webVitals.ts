import type { Metric } from "web-vitals"

/**
 * Web Vitals metrics for performance monitoring.
 * These are the Core Web Vitals that Google uses for ranking.
 *
 * - CLS (Cumulative Layout Shift): Measures visual stability
 * - FID (First Input Delay): Measures interactivity (deprecated, use INP)
 * - INP (Interaction to Next Paint): Measures responsiveness
 * - FCP (First Contentful Paint): Measures loading performance
 * - LCP (Largest Contentful Paint): Measures loading performance
 * - TTFB (Time to First Byte): Measures server response time
 */

type ReportHandler = (metric: Metric) => void

const logMetric: ReportHandler = (metric) => {
  // Log to console in development
  if (import.meta.env.DEV) {
    console.log(`[Web Vitals] ${metric.name}:`, {
      value: metric.value,
      rating: metric.rating, // 'good', 'needs-improvement', or 'poor'
      delta: metric.delta,
      id: metric.id,
    })
  }

  // In production, you could send to analytics service
  // Example: sendToAnalytics(metric)
}

export async function reportWebVitals(onReport?: ReportHandler) {
  const handler = onReport || logMetric

  const { onCLS, onFCP, onLCP, onTTFB, onINP } = await import("web-vitals")

  // Core Web Vitals
  onCLS(handler)
  onLCP(handler)
  onINP(handler)

  // Additional metrics
  onFCP(handler)
  onTTFB(handler)
}

/**
 * Get performance thresholds for Core Web Vitals.
 * Values based on Google's recommendations.
 */
export const webVitalsThresholds = {
  CLS: { good: 0.1, poor: 0.25 },
  LCP: { good: 2500, poor: 4000 }, // milliseconds
  INP: { good: 200, poor: 500 }, // milliseconds
  FCP: { good: 1800, poor: 3000 }, // milliseconds
  TTFB: { good: 800, poor: 1800 }, // milliseconds
}
