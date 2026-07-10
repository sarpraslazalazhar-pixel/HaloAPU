import * as React from "react"
import { Tooltip as TooltipPrimitive } from "@base-ui/react/tooltip"

import { cn } from "@/lib/utils"

function TooltipProvider({ ...props }: TooltipPrimitive.Provider.Props) {
  return <TooltipPrimitive.Provider data-slot="tooltip-provider" {...props} />
}

function Tooltip({ ...props }: TooltipPrimitive.Root.Props) {
  return <TooltipPrimitive.Root data-slot="tooltip" {...props} />
}

function TooltipTrigger({ ...props }: TooltipPrimitive.Trigger.Props) {
  return <TooltipPrimitive.Trigger data-slot="tooltip-trigger" {...props} />
}

function TooltipContent({
  className,
  sideOffset = 4,
  ...props
}: TooltipPrimitive.Positioner.Props) {
  return (
    <TooltipPrimitive.Positioner data-slot="tooltip-positioner" sideOffset={sideOffset}>
      <TooltipPrimitive.Popup
        data-slot="tooltip-content"
        className={cn(
          "z-50 overflow-hidden rounded-md border bg-popover px-3 py-1.5 text-xs text-popover-foreground shadow-md animate-in fade-in-0 zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95 data-side=bottom:slide-in-from-top-2 data-side=left:slide-in-from-right-2 data-side=right:slide-in-from-left-2 data-side=top:slide-in-from-bottom-2",
          className
        )}
        {...props}
      />
    </TooltipPrimitive.Positioner>
  )
}

export { Tooltip, TooltipTrigger, TooltipContent, TooltipProvider }
