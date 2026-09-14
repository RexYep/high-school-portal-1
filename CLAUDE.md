# CLAUDE.md

Instructions for AI assistants working in this repository. Read this before every task.
This file is intentionally short. The full architecture is in `docs/architecture.md` — read the
relevant section of it before implementing anything, and do not re-read the whole document.

---

## 1. Project

**High School Portal & School Management System** — a Student Information System (SIS) for a
Philippine DepEd K-12 high school (Junior High Grades 7–10, Senior High Grades 11–12).

It is the authoritative record for: learners, staff, academic structure, enrollment, attendance,
grades, and school communication.

**It is NOT an LMS.** No lessons, quizzes, submissions, discussion boards, or course content.
If a request drifts toward LMS features, stop and flag it.

**Current phase:** `Phase 1 — Foundation`
_(update this line at the start of every phase; the phase definitions are in `docs/architecture.md` §25)_

---

## 2. Stack — fixed, do not substitute

| Layer | Choice |
|---|---|
| Backend | Laravel 12, PHP 8.3 |
| Database | MySQL 8 (InnoDB, utf8mb4_unicode_ci) |
| Templating | Blade + Blade components |
| Interactivity | Alpine.js 3 |
| CSS | Bootstrap 5.3 + CSS custom properties in `resources/css/tokens.css` |
| Build | Vite |
| Auth | Laravel Breeze (Blade stack), session-based |
| Permissions | spatie/laravel-permission + hand-written Policies |
| Queue / Cache | `database` driver |
| PDF / Excel | barryvdh/laravel-dompdf / maatwebsite/excel |
| Tests | Pest |
| Static analysis | Larastan level 5 |
| Style | Laravel Pint |

**Do not introduce** React, Vue, Inertia, Livewire, htmx, Tailwind, Redis, Docker, or any new
composer/npm package without asking first. Adding a dependency is a decision, not an implementation
detail.

---

## 3. Where things go

```
app/Http/Controllers/{Admin,Registrar,Teacher,Student,Guardian,Guidance,Finance,Shared}/
app/Http/Requests/          one per write action
app/Policies/               one per model with scoped access
app/Services/{Academic,Admission,Attendance,Enrollment,Grading,Finance,Guidance,Notification,Reporting,Support}/
app/Models/                 flat
app/Enums/                  all status values
app/Events/ app/Listeners/ app/Jobs/ app/Notifications/ app/Observers/
resources/views/{layouts,components,admin,registrar,teacher,student,guardian,reports,pdf}/
routes/{web,auth,admin,registrar,teacher,student,guardian,guidance,finance,internal}.php
database/seeders/{Reference,Demo}/
docs/                       architecture.md, decisions/, business-rules.md, permissions.md, runbook.md
tests/{Unit,Feature,Authorization}/
```

---

## 4. Naming conventions

| Element | Convention | Example |
|---|---|---|
| Tables | snake_case, plural | `class_offerings` |
| Pivot tables | singular, alphabetical | `student_guardian` |
| Columns | snake_case; FKs `<singular>_id` | `academic_year_id` |
| Booleans | `is_` / `has_` prefix | `is_current`, `has_portal_access` |
| Models | StudlyCase singular | `ClassOffering` |
| Services | `<Domain><Action>Service` | `GradeComputationService` |
| Form Requests | `<Action><Model>Request` | `StoreEnrollmentRequest` |
| Policies | `<Model>Policy` | `ClassGradePolicy` |
| Routes | kebab URIs, dot names | `registrar.enrollments.store` |
| Permissions | `domain.action[.qualifier]` | `grade.approve` |
| Tests | `it_<expected behaviour>` | `it_prevents_a_teacher_from_encoding_another_teachers_class` |

**Never use `class` as an identifier** — PHP reserved word. The table is `class_offerings`.

All timestamps stored UTC, displayed Asia/Manila. Money `DECIMAL(12,2)`, never float.
Computed grades `DECIMAL(6,2)`, transmuted grades `TINYINT UNSIGNED`.

---

## 5. Non-negotiable rules

These are not style preferences. Violating them causes data loss, privacy breaches, or both.

### Authorization — three layers, all required
1. Route middleware: `->middleware('permission:grade.encode')`
2. Policy in the controller: `$this->authorize('encode', $classOffering)`
3. Query scope on every list: `->visibleTo($user)`

Never check a role name in code (`if ($user->hasRole('Principal'))`). Check permissions.
Never infer ownership from a route parameter. Re-authorize server-side, always.
Hiding a menu item is not authorization.

### Controllers are thin
Resolve a Form Request → authorize → call ONE service method → return a view or redirect.
If a controller method passes ~25 lines, the logic belongs in a service. No business rules,
no queries with joins, no conditionals about domain state in controllers or Blade.

### Services own transactions
Multi-step operations run inside `DB::transaction()`. A partial enrollment, a partial grade
submission, or a payment without a ledger entry must be impossible.

### One entry point per operation
`EnrollmentService::enroll()` is the only path that creates an enrollment. Do not add a second
path "just for the importer" or "just for the seeder". Call the service.

### Never hard-delete academic, financial, or audit data
No `delete()` on: enrollments, class_grades, attendance_records, payments, student_ledger_entries,
audit_logs, class_grade_histories, login_histories. Use status/void semantics.
Soft deletes apply only to: users, students, employees, guardians, subjects, sections, rooms,
announcements.

### Validation is server-side
Every write action has a Form Request with explicit rules. Never `$model->update($request->all())`.
Every model has explicit `$fillable`. `status`, `approved_by`, role fields, and monetary amounts are
never fillable — set them in services.

### Audit every state change
State-changing actions write an audit entry in the same transaction as the change.
Never log passwords, tokens, session IDs, or guidance note content.

### Private files are never public
Nothing personal in `public/`. All private files served through an authenticated controller that
checks the owning entity's policy. Stored filenames are generated ULIDs, never user-supplied.

---

## 6. Domain invariants that must never break

Full list in `docs/business-rules.md` (BR-001…BR-094). The ones most often broken by generated code:

- **BR-031** One enrollment per learner per academic year — enforced by a DB unique constraint, not just the service.
- **BR-035** Changing a section writes an `enrollment_section_histories` row. Never overwrite the previous assignment.
- **BR-051** Grades are only writable while the grading period is `open` — checked on every write, not just when rendering the page.
- **BR-053/054** A locked grade changes only through an approved correction request. The old value is retained permanently.
- **BR-055** Learners and guardians never see `draft`, `submitted`, or `returned` grades.
- **BR-057** A missing grade carries an explicit reason code. It is never stored or shown as zero.
- **BR-014** A guardian sees only learners linked to them AND verified by the registrar. This is the highest-severity rule in the system.
- **BR-043** A teacher records attendance only for classes they are assigned to.
- **BR-070/072** Guidance notes are unreadable by every non-guidance role, including Principal. A teacher can create a referral but never read the case.
- **BR-059** Changing a grading scheme never retroactively alters an approved grade.
- **BR-076** A learner's balance is derived from ledger entries, never a stored editable field.

---

## 7. Workflow for every change

1. Read the relevant section of `docs/architecture.md` (schema §9, permissions §6, rules §8, the phase in §25).
2. State which tables and relationships are affected.
3. State which backend components are affected (model, service, policy, request, job).
4. State which frontend components are affected (route, view, component).
5. Implement the smallest coherent unit — one migration + model + policy + service method + controller + view + test.
6. Write the authorization test **before** the controller is considered done.
7. Run `php artisan test`. Run the full suite, not just the new file.
8. Self-review for: missing `authorize()`, mass-assignment exposure, missing transaction, N+1 query, logic leaked into the controller.
9. Commit.
10. If a design decision changed, update `docs/architecture.md` and add an ADR in `docs/decisions/`.

### Definition of done for any feature
- [ ] Migration has the right constraints (unique, FK, check) — not just application validation
- [ ] Model has `$fillable`, casts, enums, relationships, and a `visibleTo` scope if listed anywhere
- [ ] Policy exists and every controller action calls `authorize()`
- [ ] Form Request exists for every write
- [ ] Service method is transactional and is the only entry point
- [ ] Audit entry written for state changes
- [ ] Feature test for the happy path
- [ ] **Authorization test asserting the denials**, not just the allows
- [ ] Pint and Larastan pass
- [ ] Full test suite green

---

## 8. Testing

- Test what is catastrophic if wrong, not line coverage.
- **100% required:** the authorization matrix (`docs/architecture.md` §6.3), grade computation, grade workflow transitions.
- Assert **denials**. A suite that only proves the happy path proves nothing about security.
- Every bug fix begins with a failing test reproducing the bug.
- Use factories and the `AcademicScenario` builder. Deterministic seeds — no random data that fails once a week.
- Never test against a shared or production database.

---

## 9. Commits

Conventional Commits: `<type>(<scope>): <subject>`
Types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `security`.

```
feat(grades): add quarterly computation with configurable weights
fix(attendance): prevent duplicate records on double submit
security(files): enforce policy check on private downloads
```

Commit small. Branch per feature: `feature/<phase>-<slug>` (e.g. `feature/07-grade-encoding`).
Never commit directly to `main`. Tag at the end of each phase (`v0.7.0-grades`).

---

## 10. Never do these

- Rewrite, reformat, or "clean up" code you were not asked to change.
- Refactor unrelated files while fixing a bug.
- Remove functionality without first identifying what it did and saying so.
- Invent requirements. If it is not in `docs/architecture.md`, it is not a requirement.
- Change a business rule silently. Rules change in `docs/business-rules.md` first.
- Add a package, a table, or a new pattern without asking.
- Put `{!! !!}` around user input in Blade.
- Leave `dd()`, `dump()`, `ray()`, or commented-out code in a commit.
- Use `env()` outside `config/` files — it returns null once configs are cached in production.
- Generate an entire module in one pass. One coherent unit at a time.

---

## 11. Ask before assuming

Stop and ask when the task touches:
- Grading weights, the transmutation table, or the passing mark (must match the registrar's actual figures)
- Who approves grades, and whether approver ≠ submitter is enforceable
- Whether SHS semestral subjects apply (changes the schema)
- Report card / SF9 / SF10 layout requirements
- Anything involving guidance note content
- Anything that would require a destructive migration

When something is genuinely ambiguous and minor: state the assumption in the commit message,
choose the most reasonable engineering option, and continue. Do not stall on small unknowns.

---

## 12. Commands

```bash
php artisan test                      # full suite
php artisan test --filter=Enrollment  # focused
./vendor/bin/pint                     # format
./vendor/bin/phpstan analyse          # static analysis
php artisan migrate:fresh --seed      # rebuild dev database
php artisan school:demo-reset         # rebuild the demo scenario
npm run dev                           # Vite dev server
npm run build                         # production assets
```

---

## 13. Documentation map

| File | Contents |
|---|---|
| `docs/architecture.md` | The full plan. §6 permissions, §8 business rules, §9 schema, §10 ERDs, §14 UI, §25 roadmap, §27 edge cases |
| `docs/business-rules.md` | BR-001…BR-094 with stable IDs |
| `docs/permissions.md` | The permission catalog |
| `docs/decisions/` | ADRs — one page each: context, decision, consequences |
| `docs/runbook.md` | Deploy, restore, incident procedures |

Keep §6, §8, and §9 of the architecture document current above everything else. The narrative
sections may age; those three must not.
