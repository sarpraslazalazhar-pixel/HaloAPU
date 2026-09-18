import React from 'react';
import {
  FileText, Wrench, Car, Building, Building2, Laptop, Server, 
  Printer, Wifi, User, Shield, HelpCircle, Phone, Mail, 
  Calendar, Clock, Key, Database, Settings, AlertCircle, 
  Inbox, Briefcase, Bookmark, Compass, Cpu, HardDrive, 
  Headphones, Image, Layers, Lock, MapPin, MessageSquare, 
  Monitor, Paperclip, Radio, Tv, Zap, Star, Heart,
  CreditCard, DollarSign, Gift, Truck, CheckCircle, type LucideIcon
} from 'lucide-react';

const ICON_MAP = {
  FileText, Wrench, Car, Building, Building2, Laptop, Server, 
  Printer, Wifi, User, Shield, HelpCircle, Phone, Mail, 
  Calendar, Clock, Key, Database, Settings, AlertCircle, 
  Inbox, Briefcase, Bookmark, Compass, Cpu, HardDrive, 
  Headphones, Image, Layers, Lock, MapPin, MessageSquare, 
  Monitor, Paperclip, Radio, Tv, Zap, Star, Heart,
  CreditCard, DollarSign, Gift, Truck, CheckCircle
} satisfies Record<string, LucideIcon>;

interface DynamicIconProps {
  name?: string | null;
  className?: string;
  fallback?: string;
}

export function DynamicIcon({ name, className = 'w-5 h-5', fallback = 'FileText' }: DynamicIconProps) {
  // SAFETY: Look up fallback icon in ICON_MAP using keyof; fallback to FileText component if not found.
  const FallbackComponent = ICON_MAP[fallback as keyof typeof ICON_MAP] ?? FileText;

  if (!name) {
    return <FallbackComponent className={className} />;
  }

  // Normalize name
  const formattedKey = name.charAt(0).toUpperCase() + name.slice(1);
  // SAFETY: Look up icon by exact or capitalized name in ICON_MAP; fallback to FallbackComponent if not found.
  const IconComponent = ICON_MAP[name as keyof typeof ICON_MAP] ?? ICON_MAP[formattedKey as keyof typeof ICON_MAP] ?? FallbackComponent;

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
