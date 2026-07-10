import * as React from "react"
import { Toast as ToastPrimitive } from "@base-ui/react/toast"
import { cva, type VariantProps } from "class-variance-authority"

import { cn } from "@/lib/utils"
import { XIcon } from "lucide-react"

const toastVariants = cva(
  "group/toast pointer-events-auto relative flex w-full items-center justify-between gap-3 rounded-lg border p-4 text-sm shadow-lg transition-all data-open:animate-in data-open:fade-in-0 data-open:slide-in-from-top-2 data-closed:animate-out data-closed:fade-out-0 data-closed:slide-out-to-top-2 data-swiped:slide-out-to-right-full",
  {
    variants: {
      variant: {
        default: "bg-background text-foreground border-border",
        success: "bg-green-50 text-green-800 border-green-200 dark:bg-green-950 dark:text-green-200 dark:border-green-800",
        error: "bg-red-50 text-red-800 border-red-200 dark:bg-red-950 dark:text-red-200 dark:border-red-800",
        warning: "bg-yellow-50 text-yellow-800 border-yellow-200 dark:bg-yellow-950 dark:text-yellow-200 dark:border-yellow-800",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Toast({
  className,
  variant = "default",
  ...props
}: ToastPrimitive.Props & VariantProps<typeof toastVariants>) {
  return (
    <ToastPrimitive
      data-slot="toast"
      className={cn(toastVariants({ variant, className }))}
      {...props}
    />
  )
}

function ToastTitle({ className, ...props }: ToastPrimitive.Title.Props) {
  return (
    <ToastPrimitive.Title
      data-slot="toast-title"
      className={cn("font-medium [&+*]:text-xs", className)}
      {...props}
    />
  )
}

function ToastDescription({ className, ...props }: ToastPrimitive.Description.Props) {
  return (
    <ToastPrimitive.Description
      data-slot="toast-description"
      className={cn("text-sm opacity-90", className)}
      {...props}
    />
  )
}

function ToastClose({ className, ...props }: ToastPrimitive.Close.Props) {
  return (
    <ToastPrimitive.Close
      data-slot="toast-close"
      className={cn(
        "absolute top-2 right-2 rounded-md p-1 text-foreground/50 opacity-0 transition-opacity hover:text-foreground focus:opacity-100 focus:outline-none focus:ring-2 group-hover/toast:opacity-100 group-data-[swiped]/toast:opacity-100",
        className
      )}
      {...props}
    >
      <XIcon className="h-4 w-4" />
    </ToastPrimitive.Close>
  )
}

export { Toast, ToastTitle, ToastDescription, ToastClose, toastVariants }
