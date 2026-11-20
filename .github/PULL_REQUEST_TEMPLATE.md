## Summary
Brief summary of the changes and motivation.

## Checklist
- [ ] `composer validate --strict`, `composer test`, `composer analyse` (PHPStan L8), and `composer lint` (Pint) pass on PHP 8.4+
- [ ] `npm run typecheck`, `npm run test` (Vitest), and `npm run build` pass
- [ ] If `resources/js/` changed, rebuilt `dist/` is included in this PR
- [ ] If geometry changed, both `src/Geometry/` and `resources/js/shared/geometry.ts` + `tests/fixtures/geometry/cases.json` were updated together
- [ ] Documentation (`README.md` / `docs/INSTALLATION.md` / `CHANGELOG.md`) updated if user-facing behavior changed
