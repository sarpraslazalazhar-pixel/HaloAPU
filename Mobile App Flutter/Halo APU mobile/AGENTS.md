# AGENTS.md

## Status Proyek
- Tahap **UI/UX prototype** - belum ada integrasi backend; semua provider memakai mock data (contoh: `lib/presentation/tickets/providers/user_ticket_provider.dart`).
- `PRDFlutter.md` = sumber kebenaran desain & scope mobile; `Halo APU.md` = dokumentasi fitur web app (Laravel) sebagai referensi backend.

## Perintah
- `flutter analyze` - lint/static check
- `flutter test` - test (saat ini hanya smoke test)
- `dart run build_runner build --delete-conflicting-outputs` - wajib setelah mengubah model Freezed; file `.freezed.dart`/`.g.dart` di-generate dan ikut di-commit
- `flutter run` - jalankan app

## Arsitektur & Konvensi
- `lib/core/` (theme, router), `lib/domain/models/` (Freezed), `lib/presentation/<fitur>/` berisi `*_screen.dart`, `widgets/`, `providers/`.
- State: Riverpod `StateNotifierProvider<..., AsyncValue<T>>`.
- go_router: route detail menerima model via `state.extra` (cast `as TicketModel`), bukan path param.
- Model: `@freezed sealed class` + enum dengan `@JsonValue(...)` (lihat `lib/domain/models/ticket_model.dart`).
- Design tokens terpusat di `lib/core/theme/app_theme.dart` (warna/radius/font dari PRD section 10) - jangan hardcode warna/radius di screen.

## Gotcha
- Package dari PRD section 17 (dio, hive, firebase_messaging, dll.) **belum ada di pubspec.yaml** - jangan tambah dependency sebelum fase integrasi API.
- Modul backoffice (Form Builder, Master Data, SLA Config, Analytics, User Management) **out of scope** mobile (PRD section 6) - jangan buat screen-nya.
- Bukan git repo (belum `git init`).
- Bahasa UI & komentar mengikuti dokumen: Indonesia.
