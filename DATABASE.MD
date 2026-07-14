Database Documentation – SafetyFlash

Overview

This document describes the database architecture of the SafetyFlash platform.

The database stores:

* SafetyFlash records
* Users and permissions
* Workflow history
* Feedback and corrective actions
* Analytics events
* Display playlists
* Push notification subscriptions
* System settings

The database is designed to support the complete lifecycle of a SafetyFlash from creation to publication, distribution, analytics and archiving.

⸻

Core Data Model

The platform is built around the SafetyFlash record.

Main relationship:

Users
→ SafetyFlashes
→ Workflow
→ Feedback
→ Analytics
→ Display System

⸻

SafetyFlash Records

sf_flashes

Primary table containing all SafetyFlash records.

Includes:

* First Releases
* Dangerous Situations
* Investigation Reports

Typical information:

* Title
* Description
* Flash type
* Status
* Creator
* Worksite
* Publication information
* Workflow assignments

This table represents the central entity of the entire application.

⸻

User Management

sf_users

Stores application users.

Includes:

* Authentication data
* Language preference
* Profile information
* Notification preferences

⸻

User Roles

Roles are stored separately from operational SafetyFlash data.

Examples:

* Reporter
* Site Supervisor
* Safety Team
* Communications
* Administrator

Roles control workflow permissions and visibility.

⸻

Workflow System

Workflow Data

Stores progression of SafetyFlash records through processing stages.

Typical stages:

* Draft
* Submitted
* Site Supervisor Review
* Safety Team Review
* Communications Review
* Published
* Archived

Workflow history must remain traceable.

Historical workflow entries should never be overwritten.

⸻

Investigation Reports

Investigation Reports are linked to an existing:

* First Release
* Dangerous Situation

Relationship:

Original SafetyFlash
→ Investigation Report

The relationship must remain permanent.

⸻

Worksites

Worksite Data

Stores worksite information used throughout the application.

Examples:

* Site name
* Site code
* Country
* Distribution settings

Worksites are used for:

* Filtering
* Workflow routing
* Display playlists
* Notifications

⸻

Feedback System

sf_feedback

Stores:

* Comments
* Corrective actions
* Follow-up actions
* Status updates

Relationships:

SafetyFlash
→ Feedback Entries

Feedback entries maintain a complete discussion history.

⸻

Analytics System

sf_analytics_events

Stores user activity and engagement data.

Examples:

* View
* Download
* Share
* Display view
* Push notification open

Analytics records must remain independent from operational SafetyFlash records.

⸻

Analytics Principles

Analytics data is append-only.

Events should never modify historical analytics records.

This ensures reliable reporting and auditing.

⸻

Push Notification System

Push Subscriptions

Stores browser push subscriptions.

Typical data:

* Endpoint
* Public key
* Device information
* User reference

Used for:

* Workflow notifications
* Publication notifications
* Feedback notifications
* System notifications

⸻

Notification Preferences

Stores user-specific notification settings.

Supports:

* Email notifications
* Push notifications

Each notification type can be enabled or disabled independently.

⸻

Display System

Display Targets

Stores display destinations.

Examples:

* Information screens
* Safety displays
* Site-specific displays

⸻

Playlists

Stores playlist definitions.

Supports:

* Multiple displays
* Site filtering
* Scheduling
* Expiration dates

⸻

Playlist Items

Stores SafetyFlash assignments inside playlists.

Relationship:

Playlist
→ Playlist Items
→ SafetyFlash

⸻

Translation System

Stores language-specific SafetyFlash content.

Supported languages:

* Finnish
* Swedish
* English
* Italian
* Greek

Translations remain linked to the original SafetyFlash.

Relationship:

Original SafetyFlash
→ Translation Versions

⸻

Image System

Images are stored as files.

The database stores references to:

* Original images
* Annotated images
* Generated images
* Preview images

Image files should never be embedded directly in database records.

⸻

Preview System

Stores references to generated preview assets.

Used by:

* Form preview
* Translation preview
* Display preview

Generated assets may be recreated when necessary.

⸻

PDF Generation

Generated PDF files are stored separately from source data.

The database stores only references to generated files.

Published SafetyFlash content remains the source of truth.

⸻

Auditability

The platform is designed to preserve historical information.

The following data should remain traceable:

* SafetyFlash creation
* Workflow actions
* Feedback history
* Analytics events
* Publication history

Historical records should not be overwritten when avoidable.

⸻

Data Relationships

High-level relationship model:

Users
↓
SafetyFlashes
↓
Workflow
↓
Feedback

SafetyFlashes
↓
Analytics

SafetyFlashes
↓
Translations

SafetyFlashes
↓
Playlists

Users
↓
Push Subscriptions

Worksites
↓
SafetyFlashes

Worksites
↓
Displays

⸻

Performance Considerations

Frequently queried data should be indexed.

Examples:

* SafetyFlash status
* Worksite
* Publication date
* Analytics target identifiers
* User identifiers

Dashboard and analytics queries should use database aggregation whenever possible.

Avoid processing large datasets in PHP when equivalent SQL aggregation is available.

⸻

Data Retention

SafetyFlash records are retained according to organizational policies.

Temporary files are stored separately from operational data.

Temporary process data may be removed automatically through scheduled cleanup jobs.

Analytics retention periods should be defined according to organizational reporting requirements.

⸻

Summary

The SafetyFlash database is designed around a central SafetyFlash entity and supports reporting, investigation, publication, feedback, analytics, display management, multilingual content and push notifications while maintaining traceability and long-term maintainability.
