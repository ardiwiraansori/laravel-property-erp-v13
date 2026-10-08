Checkpoint 7.5 Batch 4B.5 lint hotfix
Replace ONLY frontend/src/features/company/company-context.tsx with the bundled file.
Fixes react-hooks/set-state-in-effect by postponing initialization state changes to async continuation, and react-hooks/exhaustive-deps by depending on userId.
Run npm run lint; if successful, npm run build.
Do not change backend or migrations.
