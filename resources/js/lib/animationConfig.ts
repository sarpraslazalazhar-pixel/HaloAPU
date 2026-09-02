import { Variants, Transition } from 'framer-motion';

// Spring Physics Presets
export const smoothSpring: Transition = {
  type: 'spring',
  stiffness: 350,
  damping: 30,
};

export const softSpring: Transition = {
  type: 'spring',
  stiffness: 220,
  damping: 25,
};

export const quickEase: Transition = {
  duration: 0.25,
  ease: [0.16, 1, 0.3, 1], // Custom smooth ease-out curve
};

// Page Transition Variants
export const pageVariants: Variants = {
  initial: {
    opacity: 0,
    y: 10,
  },
  animate: {
    opacity: 1,
    y: 0,
    transition: {
      duration: 0.25,
      ease: [0.16, 1, 0.3, 1],
    },
  },
  exit: {
    opacity: 0,
    y: -8,
    transition: {
      duration: 0.18,
      ease: [0.7, 0, 0.84, 0],
    },
  },
};

// Modal & Dialog Scale-Pop Variants
export const modalOverlayVariants: Variants = {
  initial: { opacity: 0 },
  animate: { opacity: 1, transition: { duration: 0.2 } },
  exit: { opacity: 0, transition: { duration: 0.15 } },
};

export const modalContentVariants: Variants = {
  initial: {
    opacity: 0,
    scale: 0.90,
    y: 15,
  },
  animate: {
    opacity: 1,
    scale: 1,
    y: 0,
    transition: {
      type: 'spring',
      stiffness: 380,
      damping: 26,
    },
  },
  exit: {
    opacity: 0,
    scale: 0.92,
    y: 10,
    transition: {
      duration: 0.18,
      ease: [0.7, 0, 0.84, 0],
    },
  },
};

// Accordion Submenu Heights
export const accordionVariants: Variants = {
  closed: {
    height: 0,
    opacity: 0,
    overflow: 'hidden',
    transition: {
      height: { duration: 0.25, ease: [0.16, 1, 0.3, 1] },
      opacity: { duration: 0.15 },
    },
  },
  open: {
    height: 'auto',
    opacity: 1,
    overflow: 'hidden',
    transition: {
      height: { duration: 0.3, ease: [0.16, 1, 0.3, 1] },
      opacity: { duration: 0.25, delay: 0.05 },
    },
  },
};

// Sidebar Nav Item Hover
export const navItemHover: Variants = {
  rest: { x: 0 },
  hover: {
    x: 4,
    transition: { type: 'spring', stiffness: 400, damping: 25 },
  },
};

// Dropdown Popover Entrance
export const popoverVariants: Variants = {
  initial: {
    opacity: 0,
    scale: 0.95,
    y: -6,
  },
  animate: {
    opacity: 1,
    scale: 1,
    y: 0,
    transition: {
      type: 'spring',
      stiffness: 420,
      damping: 28,
    },
  },
  exit: {
    opacity: 0,
    scale: 0.96,
    y: -4,
    transition: { duration: 0.15 },
  },
};
