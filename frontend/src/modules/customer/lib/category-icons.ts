import {
  BookOpen,
  Building2,
  Cake,
  Car,
  ChefHat,
  Droplet,
  FlaskConical,
  GraduationCap,
  HeartPulse,
  Hospital,
  Pill,
  Shield,
  Sparkles,
  Stethoscope,
  Truck,
  Utensils,
  Wind,
  Wrench,
  Zap,
  type LucideIcon,
} from 'lucide-react';

interface CategoryVisual {
  icon: LucideIcon;
  bgClass: string;
  textClass: string;
}

const ICON_REGISTRY: Record<string, CategoryVisual> = {
  'heart-pulse': { icon: HeartPulse, bgClass: 'bg-rose-50', textClass: 'text-rose-600' },
  tooth: { icon: Stethoscope, bgClass: 'bg-sky-50', textClass: 'text-sky-600' },
  stethoscope: { icon: Stethoscope, bgClass: 'bg-sky-50', textClass: 'text-sky-600' },
  hospital: { icon: Hospital, bgClass: 'bg-red-50', textClass: 'text-red-600' },
  pill: { icon: Pill, bgClass: 'bg-emerald-50', textClass: 'text-emerald-600' },
  flask: { icon: FlaskConical, bgClass: 'bg-teal-50', textClass: 'text-teal-600' },
  utensils: { icon: Utensils, bgClass: 'bg-orange-50', textClass: 'text-orange-600' },
  cake: { icon: Cake, bgClass: 'bg-pink-50', textClass: 'text-pink-600' },
  'chef-hat': { icon: ChefHat, bgClass: 'bg-amber-50', textClass: 'text-amber-700' },
  wrench: { icon: Wrench, bgClass: 'bg-blue-50', textClass: 'text-blue-700' },
  droplet: { icon: Droplet, bgClass: 'bg-cyan-50', textClass: 'text-cyan-700' },
  zap: { icon: Zap, bgClass: 'bg-yellow-50', textClass: 'text-yellow-700' },
  wind: { icon: Wind, bgClass: 'bg-indigo-50', textClass: 'text-indigo-600' },
  shield: { icon: Shield, bgClass: 'bg-emerald-50', textClass: 'text-emerald-700' },
  car: { icon: Car, bgClass: 'bg-slate-100', textClass: 'text-slate-700' },
  truck: { icon: Truck, bgClass: 'bg-amber-50', textClass: 'text-amber-700' },
  'graduation-cap': { icon: GraduationCap, bgClass: 'bg-violet-50', textClass: 'text-violet-700' },
  'book-open': { icon: BookOpen, bgClass: 'bg-purple-50', textClass: 'text-purple-700' },
  sparkles: { icon: Sparkles, bgClass: 'bg-fuchsia-50', textClass: 'text-fuchsia-700' },
  building: { icon: Building2, bgClass: 'bg-brand-50', textClass: 'text-brand-600' },
};

const DEFAULT_VISUAL: CategoryVisual = {
  icon: Building2,
  bgClass: 'bg-brand-50',
  textClass: 'text-brand-600',
};

export function getCategoryVisual(iconName?: string | null): CategoryVisual {
  if (!iconName) {
    return DEFAULT_VISUAL;
  }

  return ICON_REGISTRY[iconName.toLowerCase()] ?? DEFAULT_VISUAL;
}
