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

const ICON_MAP: Record<string, LucideIcon> = {
  FileText, Wrench, Car, Building, Building2, Laptop, Server, 
  Printer, Wifi, User, Shield, HelpCircle, Phone, Mail, 
  Calendar, Clock, Key, Database, Settings, AlertCircle, 
  Inbox, Briefcase, Bookmark, Compass, Cpu, HardDrive, 
  Headphones, Image, Layers, Lock, MapPin, MessageSquare, 
  Monitor, Paperclip, Radio, Tv, Zap, Star, Heart,
  CreditCard, DollarSign, Gift, Truck, CheckCircle
};

interface DynamicIconProps {
  name?: string | null;
  className?: string;
  fallback?: string;
}

export function DynamicIcon({ name, className = 'w-5 h-5', fallback = 'FileText' }: DynamicIconProps) {
  if (!name) {
    const FallbackComponent = ICON_MAP[fallback] || FileText;
    return <FallbackComponent className={className} />;
  }

  // Normalize name
  const formattedKey = name.charAt(0).toUpperCase() + name.slice(1);
  const IconComponent = ICON_MAP[name] || ICON_MAP[formattedKey] || ICON_MAP[fallback] || FileText;

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
