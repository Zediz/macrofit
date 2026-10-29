import { Routes } from '@angular/router';
import { authGuard, guestGuard, readyGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () => import('./pages/login/login.page').then((m) => m.LoginPage),
  },
  {
    path: 'onboarding',
    canActivate: [authGuard],
    loadComponent: () => import('./pages/onboarding/onboarding.page').then((m) => m.OnboardingPage),
  },
  {
    path: 'add-food',
    canActivate: [authGuard, readyGuard],
    loadComponent: () => import('./pages/add-food/add-food.page').then((m) => m.AddFoodPage),
  },
  {
    path: 'ocr',
    canActivate: [authGuard, readyGuard],
    loadComponent: () => import('./pages/ocr/ocr.page').then((m) => m.OcrPage),
  },
  {
    path: 'recipes/new',
    canActivate: [authGuard, readyGuard],
    loadComponent: () => import('./pages/recipe-editor/recipe-editor.page').then((m) => m.RecipeEditorPage),
  },
  {
    path: 'recipes/:id',
    canActivate: [authGuard, readyGuard],
    loadComponent: () => import('./pages/recipe-editor/recipe-editor.page').then((m) => m.RecipeEditorPage),
  },
  {
    path: '',
    canActivate: [authGuard, readyGuard],
    loadComponent: () => import('./pages/tabs/tabs.page').then((m) => m.TabsPage),
    children: [
      {
        path: 'dashboard',
        loadComponent: () => import('./pages/dashboard/dashboard.page').then((m) => m.DashboardPage),
      },
      {
        path: 'recipes',
        loadComponent: () => import('./pages/recipes/recipes.page').then((m) => m.RecipesPage),
      },
      {
        path: 'profile',
        loadComponent: () => import('./pages/profile/profile.page').then((m) => m.ProfilePage),
      },
      { path: '', redirectTo: 'dashboard', pathMatch: 'full' },
    ],
  },
  { path: '**', redirectTo: 'dashboard' },
];
