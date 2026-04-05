Here is a high-level summary you can drop right into the top of your `README.md` file. It explains the "what" and the "why" of the plugin for any developer or SEO specialist who looks at your repository.

***

# Dermatology EEAT Master Authority Schema

## Overview
The **Dermatology EEAT Master Authority Schema** is a custom WordPress plugin designed to generate highly targeted, relationship-driven JSON-LD schema for dermatology clinics. 

Rather than outputting flat, isolated data, this plugin builds a **Relational Knowledge Graph** that satisfies Google’s strict E-E-A-T (Experience, Expertise, Authoritativeness, and Trustworthiness) guidelines. It programmatically connects your Clinic Locations, Physicians, Medical Procedures, and Medical Conditions together using strict Schema.org vocabulary.

By mapping definitive clinical markers—such as ICD-10 codes, SNOMED codes, anatomical structures, and provider NPIs—this plugin signals maximum medical authority to search engines, helping your site rank for complex healthcare queries.

## Core Features

* **Relational "Hub" Architecture:** Dynamically connects parent conditions (e.g., Skin Cancer) to their specific sub-types (e.g., Melanoma), automatically pulling in relevant clinical codes from child pages to build robust `differentialDiagnosis` arrays.
* **Clinical Precision:** Maps treatments and conditions to authoritative medical registries (ICD-10-CM and SNOMED-CT) and specific anatomical structures (`AnatomicalStructure`) using Advanced Custom Fields (ACF).
* **Provider Authority:** Links specific medical interventions directly to the credentials of your team. Injects "Reviewed By" and "Provider" nodes complete with NPI numbers, educational history, and medical specialties.
* **Strict Schema.org Compliance:** Adheres strictly to property domains (e.g., correctly assigning `usedToTreat` to `MedicalCondition` nodes) to ensure 100% clean validation in the Google Rich Results Test.
* **Multi-Location Support:** Automatically detects the current page context to inject the correct satellite clinic data, including GeoCoordinates, dynamic opening hours, and parent-organization relationships.

## Architecture
The script operates on a smart fork system based on WordPress post types and custom taxonomies:
1. **Fork A (Procedures):** Outputs `MedicalProcedure` schema, handling physical interventions, body locations, prep instructions, and the specific conditions they treat.
2. **Fork B (Conditions):** Outputs `MedicalCondition` schema, handling epidemiology, risk factors, symptoms, and dynamic relationships to specific subtypes.
3. **Team & Global Schema:** Generates robust `Physician` and `MedicalClinic` schema, cross-linking them to the treatments they provide.

**Dependencies:** Requires [Advanced Custom Fields (ACF) Pro](https://www.advancedcustomfields.com/) for Options pages, repeaters, and relational post-object fields.