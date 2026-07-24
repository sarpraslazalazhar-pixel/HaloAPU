import React from 'react';
import * as Icons from 'lucide-react';

interface DynamicIconProps {
 name?: string | null;
 className?: string;
 fallback?: keyof typeof Icons;
}

export function DynamicIcon({ name, className = 'w-5 h-5', fallback = 'FileText' }: DynamicIconProps) {
 if (!name) {
 const FallbackComponent = (Icons[fallback] as React.ComponentType<{ className?: string }>) || Icons.FileText;
 return <FallbackComponent className={className} />;
 }

 // Try finding exact match or capitalize first letter
 const iconKey = (name in Icons 
 ? name 
 : name.charAt(0).toUpperCase() + name.slice(1)) as keyof typeof Icons;

 const IconComponent = (Icons[iconKey] as React.ComponentType<{ className?: string }>) || (Icons[fallback] as React.ComponentType<{ className?: string }>) || Icons.FileText;

 return <IconComponent className={className} />;
}

// Popular icons list for the Icon Picker UI
export const POPULAR_ICONS = [
 'FileText', 'Wrench', 'Car', 'Building', 'Building2', 'Laptop', 'Server', 
 'Printer', 'Wifi', 'User', 'Shield', 'HelpCircle', 'Phone', 'Mail', 
 'Calendar', 'Clock', 'Key', 'Database', 'Settings', 'AlertCircle', 
 'Inbox', 'Briefcase', 'Bookmark', 'Compass', 'Cpu', 'HardDrive', 
 'Headphones', 'Image', 'Layers', 'Lock', 'MapPin', 'MessageSquare', 
 'Monitor', 'Paperclip', 'Radio', 'Tv', 'Zap', 'Star', 'Heart',
 'CreditCard', 'DollarSign', 'Gift', 'Truck', 'CheckCircle'
];
