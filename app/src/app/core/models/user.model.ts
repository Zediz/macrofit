import { ActivityLevel, Gender } from './nutrition';

export interface User {
  id: number;
  email: string;
  name: string;
  gender: Gender | null;
  age: number | null;
  weight_kg: number | null;
  height_cm: number | null;
  activity_level: ActivityLevel | null;
  bmr: number | null;
  tdee: number | null;
  goal_kcal: number | null;
  goal_protein: number | null;
  goal_carbs: number | null;
  goal_fat: number | null;
  protein_pct: number | null;
  carbs_pct: number | null;
  fat_pct: number | null;
  onboarding_complete: boolean;
}

export interface AuthResponse {
  token: string;
  user: User;
}
