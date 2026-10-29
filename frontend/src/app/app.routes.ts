import { Routes } from '@angular/router';
import { LoginComponent } from './components/login/login.component';
import { ImportsComponent } from './components/imports/imports.component';
import { authGuard, guestGuard, rootGuard } from './guards/auth.guard';

export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    canActivate: [rootGuard],
    component: LoginComponent,
  },
  {
    path: 'login',
    component: LoginComponent,
    canActivate: [guestGuard],
  },
  {
    path: 'imports',
    component: ImportsComponent,
    canActivate: [authGuard],
  },
  {
    path: '**',
    redirectTo: 'login',
  },
];
