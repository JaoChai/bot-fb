import * as React from "react"
import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"

import { cn } from "@/lib/utils"

const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-full border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary/90",
        secondary:
          "border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/90",
        destructive:
          "border-transparent bg-destructive text-white [a&]:hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 dark:bg-destructive/60",
        outline:
          "text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground",
        success:
          "border border-[color:color-mix(in_oklch,var(--success)_100%,transparent)] bg-[color:color-mix(in_oklch,var(--success)_10%,transparent)] text-success-foreground [a&]:hover:bg-[color:color-mix(in_oklch,var(--success)_15%,transparent)]",
        warning:
          "border border-[color:color-mix(in_oklch,var(--warning)_100%,transparent)] bg-[color:color-mix(in_oklch,var(--warning)_10%,transparent)] text-warning-foreground [a&]:hover:bg-[color:color-mix(in_oklch,var(--warning)_15%,transparent)]",
        info:
          "border border-[color:color-mix(in_oklch,var(--info)_100%,transparent)] bg-[color:color-mix(in_oklch,var(--info)_10%,transparent)] text-info-foreground [a&]:hover:bg-[color:color-mix(in_oklch,var(--info)_15%,transparent)]",
        inactive:
          "border border-[color:color-mix(in_oklch,var(--inactive)_100%,transparent)] bg-[color:color-mix(in_oklch,var(--inactive)_10%,transparent)] text-inactive-foreground [a&]:hover:bg-[color:color-mix(in_oklch,var(--inactive)_15%,transparent)]",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "span"

  return (
    <Comp
      data-slot="badge"
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
}

export { Badge, badgeVariants }
