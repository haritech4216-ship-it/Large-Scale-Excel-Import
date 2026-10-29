import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';
import { LoginResponse, User } from '../models/auth.model';

@Injectable({
  providedIn: 'root',
})
export class AuthService {
  private readonly http = inject(HttpClient);

  readonly baseUrl =
    typeof window !== 'undefined' &&
    (window.location.port === '4200' || window.location.hostname === 'localhost')
      ? 'http://127.0.0.1:8000/api'
      : '/api';

  private readonly TOKEN_KEY = 'excel_import_token';
  private readonly USER_KEY = 'excel_import_user';

  readonly token = signal<string | null>(this.getInitialToken());
  readonly currentUser = signal<User | null>(this.getInitialUser());
  readonly isAuthenticated = computed(() => !!this.token());

  private getInitialToken(): string | null {
    if (typeof window !== 'undefined' && window.localStorage) {
      return localStorage.getItem(this.TOKEN_KEY);
    }
    return null;
  }

  private getInitialUser(): User | null {
    if (typeof window !== 'undefined' && window.localStorage) {
      const stored = localStorage.getItem(this.USER_KEY);
      if (stored) {
        try {
          return JSON.parse(stored) as User;
        } catch {
          return null;
        }
      }
    }
    return null;
  }

  login(email: string, password: string): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${this.baseUrl}/auth/login`, { email, password })
      .pipe(
        tap((res) => {
          this.setSession(res.token, res.user);
        })
      );
  }

  logout(): Observable<{ message: string }> {
    return this.http
      .post<{ message: string }>(`${this.baseUrl}/auth/logout`, {})
      .pipe(
        tap({
          next: () => this.clearSession(),
          error: () => this.clearSession(),
        })
      );
  }

  setSession(token: string, user: User): void {
    this.token.set(token);
    this.currentUser.set(user);
    if (typeof window !== 'undefined' && window.localStorage) {
      localStorage.setItem(this.TOKEN_KEY, token);
      localStorage.setItem(this.USER_KEY, JSON.stringify(user));
    }
  }

  clearSession(): void {
    this.token.set(null);
    this.currentUser.set(null);
    if (typeof window !== 'undefined' && window.localStorage) {
      localStorage.removeItem(this.TOKEN_KEY);
      localStorage.removeItem(this.USER_KEY);
    }
  }
}
