import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../services/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './login.component.html',
  styleUrl: './login.component.css',
})
export class LoginComponent implements OnInit {
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly loginEmail = signal('');
  readonly loginPassword = signal('');
  readonly isLoggingIn = signal(false);
  readonly loginError = signal<string | null>(null);

  ngOnInit(): void {
    if (this.auth.isAuthenticated()) {
      this.router.navigate(['/imports']);
    }
  }

  onLogin(): void {
    if (!this.loginEmail() || !this.loginPassword()) {
      this.loginError.set('Please provide both email and password.');
      return;
    }

    this.isLoggingIn.set(true);
    this.loginError.set(null);

    this.auth.login(this.loginEmail(), this.loginPassword()).subscribe({
      next: () => {
        this.isLoggingIn.set(false);
        this.router.navigate(['/imports']);
      },
      error: (err) => {
        this.isLoggingIn.set(false);
        this.loginError.set(
          err?.error?.message || 'Login failed. Please verify credentials.'
        );
      },
    });
  }
}
