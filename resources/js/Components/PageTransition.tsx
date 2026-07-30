import React from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { usePage } from '@inertiajs/react';
import { pageVariants } from '@/lib/animationConfig';

interface PageTransitionProps {
  children: React.ReactNode;
  className?: string;
}

export const PageTransition: React.FC<PageTransitionProps> = ({ children, className = '' }) => {
  const { url } = usePage();

  return (
    <AnimatePresence mode="wait">
      <motion.div
        key={url}
        variants={pageVariants}
        initial="initial"
        animate="animate"
        exit="exit"
        className={`w-full flex-1 ${className}`}
      >
        {children}
      </motion.div>
    </AnimatePresence>
  );
};

export default PageTransition;
