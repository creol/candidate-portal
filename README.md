# Candidate Portal — Install & Admin Guide

A small WordPress plugin for party elections. The admin creates elections,
alphabets, and candidate accounts. Candidates log in and edit their own
public profile (photo, bio, links) — changes go live immediately. Every save
can also be versioned to a GitHub repository for full history and backup.

## Install (5 minutes)

1. In WordPress admin, go to **Plugins → Add New → Upload Plugin**.
2. Upload `candidate-portal.zip` and click **Activate**.
3. Create a new page (e.g. "Candidate Login") and put this on it:
   `[candidate_portal]`
4. Go to **Candidate Portal → Settings** and choose that page as the
   Portal page. Save.

## Everyday use

### Create an alphabet (once every ~2 years, per bylaws)
**Candidate Portal → Alphabets** → name it (e.g. `2025-2027`), optionally
set the from/to dates (reminders only — never enforced), and give each
letter a value 1–26. The same alphabet can be reused in as many elections
as you like.

### Create an election
**Candidate Portal → Elections** → type the name, pick the alphabet, save.
Copy its shortcode, e.g. `[candidate_list elections="vice-chair-2026"]`,
and paste it into any WordPress page. To show two elections together on
one page: `[candidate_list elections="vice-chair-2026,school-board-d5"]`.

### Add a candidate
**Candidate Portal → Candidates** → first name, last name, email, check
which election(s) they're in → **Add candidate and send invite**. They get
an email with a set-password link and the portal address. That's the whole
approval process. Candidates can be re-used: edit them later and check a
new election's box.

### Remove a candidate
The **Remove** button deletes their profile *and* disables their login.

## GitHub versioning (optional but recommended)

1. Create a repository on github.com (private is fine).
2. Create a **fine-grained personal access token**:
   GitHub → Settings → Developer settings → Personal access tokens →
   Fine-grained → New token. Give it access to only that repository, with
   **Contents: Read and write** permission. Set the longest expiry allowed.
3. In **Candidate Portal → Settings**, enter the repo owner, repo name,
   branch (`main`), and paste the token. Save.
4. Click **Push everything to GitHub now** once to seed the repo.

From then on, every profile save becomes a commit: candidate data as
readable JSON files under `candidates/`, photos under `photos/`, and
elections/alphabets under `data/`. GitHub's history shows who changed
what and when, and any version can be recovered.

If the token ever expires, the live site keeps working — only the GitHub
backup pauses until a new token is pasted in.

## Updating the plugin

Two options:

**Manual:** Upload the new zip via Plugins -> Add New -> Upload. WordPress
detects the existing install and asks to replace it. Click yes.

**One-click from GitHub (recommended):**
1. Create a second, **public** GitHub repository for the plugin code
   (e.g. `candidate-portal`) and upload this plugin's files to it.
2. In **Candidate Portal -> Settings**, fill in the code repository owner
   and name under "Plugin updates from GitHub."
3. When an update exists, publish a **Release** on that repo: tag it with
   the new version (e.g. `v1.0.2`) and attach the new `candidate-portal.zip`
   as a release asset.
4. WordPress checks twice a day (or use the "Check for plugin updates now"
   button). The update appears under Dashboard -> Updates with a normal
   Update button.

Keep the code repo separate from your private candidate-data repo. The code
repo must be public (it contains no secrets); the data repo stays private.

## Notes

- Candidates can only see and edit their own profile. They never see the
  WordPress dashboard.
- Sorting is by last name, then first name, using the election's alphabet.
  Apostrophes, spaces, and hyphens in names are ignored for ordering.
- Deleting an election never deletes its candidates.
