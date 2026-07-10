import * as React from "react"
import { Toaster as ToasterPrimitive } from "@base-ui/react/toast"

import { cn } from "@/lib/utils"
import { Toast, ToastTitle, ToastDescription, ToastClose } from "@/components/ui/toast"

function Toaster({ className, ...props }: ToasterPrimitive.Props) {
  return (
    <ToasterPrimitive
      data-slot="toaster"
      className={cn(
        "fixed top-0 right-0 z-[100] flex max-h-screen w-full flex-col-reverse gap-2 p-4 sm:max-w-[420px]",
        className
      )}
      {...props}
    >
      {(toast) => (
        <Toast key={toast.id} variant={toast.variant || "default"}>
          <div className="flex flex-col gap-1">
            {toast.title && <ToastTitle>{toast.title}</ToastTitle>}
            {toast.description && <ToastDescription>{toast.description}</ToastDescription>}
          </div>
          <ToastClose />
        </Toast>
      )}
    </ToasterPrimitive>
  )
}

export { Toaster }
