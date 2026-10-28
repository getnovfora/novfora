# NovFora Multi-Agent UI/UX Refactor Plan & Prompts

## 1. The Multi-Agent Strategy
This document outlines the UI/UX review for NovFora (v1.0) and delegates specific refinement tasks across an AI stack. 

* **Cursor + Codex:** Frontend iterators. Used for immediate UI tweaks, CSS/Tailwind adjustments, and visual hierarchy directly in the Blade templates.
* **Claude Code (You):** The refactor agent. Responsible for sweeping architectural changes, component extraction, and backend logic standardization across the repository.
* **Google Antigravity:** The environment tester. Used to orchestrate and test the "One codebase, two tiers" deployment architecture (Baseline vs. Enhanced).
* **Manus:** The operations intern. Handles product research, competitive UI analysis in the browser, and documentation bootstrapping.

## 2. UI/UX Review of NovFora (v1.0)

**Strengths:**
* Highly semantic HTML structure (excellent for SEO and accessibility).
* Clear value proposition and feature comparison on the landing page.
* Retains the familiar, classic category-and-board structure forum veterans expect.

**Areas for Refinement:**
* **Visual Hierarchy:** The primary "New Topic" button is visually overpowering ("Big blue button is BIG and blue"). Needs softer or secondary styling.
* **Mobile Information Density:** The classic "Topics / Posts / Latest Activity" index layout is too cluttered on mobile breakpoints. Raw counts should be hidden on mobile to prioritize the "Latest Activity" card.
* **Onboarding:** Empty states need beautifully designed placeholders to encourage posting.

---

## 3. Prompts & Task Delegation

### For Cursor + Codex (Frontend UI/UX)
**Context:** Use inside the Cursor IDE's Composer panel while viewing `resources/views` files.

* **Task 1 (Visual Hierarchy):** "Review the visual hierarchy in the main forum index Blade template. The primary 'New Topic' button currently visually overpowers the layout. Refactor the Tailwind CSS classes on this button to adopt a secondary, outlined style, or a softer brand-aligned color. Ensure the text-to-background contrast ratio strictly passes WCAG AA accessibility standards."
* **Task 2 (Mobile Responsiveness):** "Analyze the Blade component responsible for rendering the forum index row. On mobile breakpoints (under 768px), the data density is too high. Refactor the Tailwind grid/flexbox utility classes to hide the raw 'Topics' and 'Posts' columns on mobile devices only. Expand the 'Latest Activity' block to take up the remaining width below the category title, preserving the exact current layout for desktop viewports."

### For Claude Code (Your Tasks)
**Context:** Run these sweeping refactors directly in the terminal at the root of the `getnovfora/novfora` repository.

* **Task 1 (Component Extraction):** "Scan the `resources/views` directory for all instances where the forum statistics (Topics, Posts, Latest Activity) are written using hardcoded `<dl>` HTML tags. Extract this HTML structure into a new reusable Laravel anonymous Blade component (e.g., `<x-forum-stats>`). Automatically update all template files to use this new component syntax, passing in the required PHP variables. Run the pest/phpunit test suite afterward to verify no views were broken."
* **Task 2 (Timestamp Standardization):** "Find all instances in the `app/` directory and `resources/views/` where timestamps are formatted for frontend display. Standardize this logic globally. Refactor the codebase so all dates leverage Laravel's Carbon `diffForHumans()` method for any timestamp under 24 hours (outputting relative time), and a standardized absolute date format (e.g., 'M j, Y') for anything older."

### For Google Antigravity (Architecture Testing)
**Context:** Use within the Antigravity command center interface.

* **Task (Tiered Deployment Simulation):** "Provision two isolated Linux sandboxes to test the `main` branch of the NovFora repository. Sandbox A (Baseline): Configure with PHP 8.3, MySQL/MariaDB, a standard cron job, and file-based caching to simulate a $5 shared host. Sandbox B (Enhanced): Configure using Docker Compose, including Redis, Meilisearch, and Reverb/Pusher for WebSockets. Deploy the NovFora codebase to both environments and run database seeders. Execute an automated script simulating 50 concurrent users logging in and posting topics. Generate a terminal report comparing performance and ensuring that Sandbox A gracefully falls back without any fatal errors missing the Enhanced services."

### For Manus (Research & Documentation)
**Context:** Use in the Manus browser interface.

* **Task 1 (Competitive UI Analysis):** "Navigate to the public demo forums for XenForo and Discourse. Analyze their mobile responsive layouts, specifically focusing on the forum index pages. Take screenshots of how they handle dense information like post counts, last-reply avatars, and sub-forum lists on a narrow screen. Generate a markdown report outlining the UI patterns they use, noting what works well and what feels cluttered, to inform the next design iteration of NovFora."
* **Task 2 (Migration Guide Bootstrapping):** "Search for and review the official database schema documentation for phpBB 3.3. Compare it against the core entities in the NovFora project. Draft a comprehensive markdown guide titled `phpBB-Migration.md` for server administrators. Include a Markdown table mapping the most critical phpBB tables (such as `phpbb_users`, `phpbb_topics`, and `phpbb_posts`) to their exact NovFora equivalents to support the development of the resumable importer."
