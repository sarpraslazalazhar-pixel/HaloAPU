import * as React from "react";
import * as AlertDialogPrimitives from "@radix-ui/react-alert-dialog";
import { cn } from "@/lib/utils";
import { buttonVariants } from "@/Components/ui/button";

const AlertDialog = AlertDialogPrimitives.Root;
const AlertDialogTrigger = AlertDialogPrimitives.Trigger;
const AlertDialogPortal = AlertDialogPrimitives.Portal;

const AlertDialogOverlay = React.forwardRef<
  React.ElementRef<typeof AlertDialogPrimitives.Overlay>,
  React.ComponentPropsWithoutRef<typeof AlertDialogPrimitives.Overlay>
>(({ className, ...props }, ref) => (
  <AlertDialogPrimitives.Overlay
    className={cn(
      "fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm",
      "animate-[dialog-overlay-in_0.35s_cubic-bezier(0.16,1,0.3,1)_forwards]",
      "data-[state=closed]:animate-[dialog-overlay-out_0.25s_ease-in_forwards]",
      className
    )}
    {...props}
    ref={ref}
  />
));
AlertDialogOverlay.displayName = AlertDialogPrimitives.Overlay.displayName;

const AlertDialogContent = React.forwardRef<
  React.ElementRef<typeof AlertDialogPrimitives.Content>,
  React.ComponentPropsWithoutRef<typeof AlertDialogPrimitives.Content>
>(({ className, ...props }, ref) => (
  <AlertDialogPortal>
    <AlertDialogOverlay />
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 pointer-events-none">
      <AlertDialogPrimitives.Content
        ref={ref}
        className={cn(
          "pointer-events-auto relative grid w-full max-w-lg max-h-[calc(100vh-3rem)] overflow-y-auto gap-4 rounded-2xl bg-popover p-6 text-sm text-popover-foreground shadow-[0_16px_70px_-12px_rgba(0,0,0,0.18)] border border-slate-200/60 outline-none sm:max-w-md",
          "animate-[dialog-content-in_0.4s_cubic-bezier(0.34,1.56,0.64,1)_forwards]",
          "data-[state=closed]:animate-[dialog-content-out_0.25s_cubic-bezier(0.4,0,1,1)_forwards]",
          className
        )}
        {...props}
      />
    </div>
  </AlertDialogPortal>
));
AlertDialogContent.displayName = AlertDialogPrimitives.Content.displayName;

const AlertDialogHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div className={cn("flex flex-col space-y-2 text-center sm:text-left", className)} {...props} />
);
AlertDialogHeader.displayName = "AlertDialogHeader";

const AlertDialogFooter = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div className={cn("flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2", className)} {...props} />
);
AlertDialogFooter.displayName = "AlertDialogFooter";

const AlertDialogTitle = React.forwardRef<
  React.ElementRef<typeof AlertDialogPrimitives.Title>,
  React.ComponentPropsWithoutRef<typeof AlertDialogPrimitives.Title>
>(({ className, ...props }, ref) => (
  <AlertDialogPrimitives.Title ref={ref} className={cn("text-lg font-semibold", className)} {...props} />
));
AlertDialogTitle.displayName = AlertDialogPrimitives.Title.displayName;

const AlertDialogDescription = React.forwardRef<
  React.ElementRef<typeof AlertDialogPrimitives.Description>,
  React.ComponentPropsWithoutRef<typeof AlertDialogPrimitives.Description>
>(({ className, ...props }, ref) => (
  <AlertDialogPrimitives.Description ref={ref} className={cn("text-sm text-muted-foreground", className)} {...props} />
));
AlertDialogDescription.displayName = AlertDialogPrimitives.Description.displayName;

const AlertDialogAction = React.forwardRef<
  React.ElementRef<typeof AlertDialogPrimitives.Action>,
  React.ComponentPropsWithoutRef<typeof AlertDialogPrimitives.Action>
>(({ className, ...props }, ref) => (
  <AlertDialogPrimitives.Action
    ref={ref}
    className={cn(buttonVariants(), "transition-transform active:scale-95 cursor-pointer", className)}
    {...props}
  />
));
AlertDialogAction.displayName = AlertDialogPrimitives.Action.displayName;

const AlertDialogCancel = React.forwardRef<
  React.ElementRef<typeof AlertDialogPrimitives.Cancel>,
  React.ComponentPropsWithoutRef<typeof AlertDialogPrimitives.Cancel>
>(({ className, ...props }, ref) => (
  <AlertDialogPrimitives.Cancel
    ref={ref}
    className={cn(buttonVariants({ variant: "outline" }), "mt-2 sm:mt-0 transition-transform active:scale-95 cursor-pointer", className)}
    {...props}
  />
));
AlertDialogCancel.displayName = AlertDialogPrimitives.Cancel.displayName;

export {
  AlertDialog,
  AlertDialogPortal,
  AlertDialogOverlay,
  AlertDialogTrigger,
  AlertDialogContent,
  AlertDialogHeader,
  AlertDialogFooter,
  AlertDialogTitle,
  AlertDialogDescription,
  AlertDialogAction,
  AlertDialogCancel,
};
