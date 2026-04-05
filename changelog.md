# Dermatology EEAT Master Authority Schema

**Description:** WordPress PHP Plugin to generate highly targeted, relationship-driven medical schema. Maps clinics, physicians, treatments, and conditions using advanced `mainEntity` and `@graph` architectures to satisfy Google's strict E-E-A-T guidelines and Schema.org validation.
**Current Version:** 2.6

---

## Changelog

### [v2.6] - Relational Codes & SNOMED Expansion
* **Fork A (Procedures) Code Injection:** Added a loop to pull `icd-10` and `snowmed` values from the `codes` repeater directly into the `$procedure_entity` node.
* **Fork B (Conditions) Main Post Fix:** Resolved an oversight where the main condition page was skipping its own clinical codes. It now correctly pulls `icd-10` and `snowmed` from the `codes` repeater on the parent post.
* **Sub-Type SNOMED Support:** Updated the relational `sub_types` loop to extract `snowmed` codes in addition to `icd-10` from child condition pages.

### [v2.5] - Procedure Condition Simplification
* **Repeater Refactoring:** Streamlined the `medical_conditions_repeater` inside Fork A to only process `internal_condition` sub-fields (Post Objects). Removed deprecated legacy array logic.
* **Manual Condition Input:** Introduced support for the new `medical_conditions_outside_objects` textarea field in Fork A. The script now automatically separates line breaks into individual `MedicalCondition` nodes containing `name` and `relevantSpecialty`.
* **Preservation Guarantee:** Ensured zero alterations to the core `MedicalWebPage` wrapper, provider loops, and satellite clinic injections.

### [v2.4] - The Relational Hub Upgrade
* **Dynamic Sub-Types:** Rebuilt Fork B (Conditions) to act as a "Hub." Added a loop to process the `sub_types` ACF relationship field, allowing parent conditions (e.g., Skin Cancer) to dynamically extract and list child conditions (e.g., Melanoma) within its `differentialDiagnosis` array.
* **Bidirectional Code Extraction:** Programmed the hub to reach into child posts and pull their specific `icd-10` codes to strengthen the parent page's clinical authority.
* **Strict Schema Domains:** Ensured properties align strictly with Schema.org domains (e.g., grouping `MedicalCode` under `MedicalCondition` nodes rather than forcing them into procedures) to pass the Google Rich Results validator.
* **Restoration:** Confirmed the preservation of all legacy V2.3 features, including Anatomy mapping, Symptoms, Causes, Shared Providers, and Team/Satellite Clinic generation.

### [v2.3] - Base E-E-A-T Architecture
* Established the core `MedicalWebPage` and `mainEntity` JSON-LD structure.
* Implemented multi-location discovery and primary clinic logic.
* Added standard `MedicalProcedure` and `MedicalCondition` forks.
* Integrated the Shared Providers logic to link `Physician` nodes with NPIs to their respective treatments and clinics.