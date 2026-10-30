import { Routes } from '@angular/router';
import { LoginComponent } from './components/login/login.component';
import { ImportsComponent } from './components/imports/imports.component';
import { ImportedDataComponent } from './components/imported-data/imported-data.component';
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
    path: 'imported-data',
    component: ImportedDataComponent,
    canActivate: [authGuard],
  },
  {
    path: '**',
    redirectTo: 'login',
  },
];
