import React from 'react';
import { usePage } from '@inertiajs/react';

interface PageTransitionProps {
  children: React.ReactNode;
  className?: string;
}

export const PageTransition: React.FC<PageTransitionProps> = ({ children, className = '' }) => {
  const { url } = usePage();

  return (
    <div
      key={url}
      className={`w-full flex-1 animate-[page-in_0.2s_ease-out] ${className}`}
    >
      {children}
    </div>
  );
};

export default PageTransition;
