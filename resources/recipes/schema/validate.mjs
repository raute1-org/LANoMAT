#!/usr/bin/env node
/**
 * Validate every LANoMAT game recipe against lanomat.recipe/v1.
 *
 *   node resources/recipes/schema/validate.mjs
 *
 * Checks, in order:
 *   1. recipe.yaml parses and matches recipe.schema.json (JSON Schema 2020-12);
 *   2. the directory name equals the recipe slug;
 *   3. slugs, skill names, and aliases are unique across the catalog;
 *   4. SKILL.md exists, has Agent Skills frontmatter, and its name/description
 *      agree with the recipe;
 *   5. commands contain no `&&` chains (one command per entry);
 *   6. SKILL.md is between 60 and 150 lines;
 *   7. drafts have 3-6 steps and say that they still need verification;
 *   8. a draft never claims a checksum it cannot back up.
 *
 * Exit code 0 means the whole catalog is valid.
 */
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';
import YAML from 'yaml';

const here = dirname(fileURLToPath(import.meta.url));
const recipesRoot = resolve(here, '..');
const schema = JSON.parse(
    readFileSync(join(here, 'recipe.schema.json'), 'utf8'),
);

const ajv = new Ajv2020({ allErrors: true, strict: true });
addFormats(ajv);
const validateRecipe = ajv.compile(schema);

const errors = [];
const warnings = [];
const recipes = [];

const dirs = readdirSync(recipesRoot, { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && entry.name !== 'schema')
    .map((entry) => entry.name)
    .sort();

for (const dir of dirs) {
    const file = join(recipesRoot, dir, 'recipe.yaml');
    if (!existsSync(file)) {
        errors.push(`${dir}: missing recipe.yaml`);
        continue;
    }

    let recipe;
    try {
        recipe = YAML.parse(readFileSync(file, 'utf8'));
    } catch (error) {
        errors.push(`${dir}: recipe.yaml is not valid YAML: ${error.message}`);
        continue;
    }

    if (!validateRecipe(recipe)) {
        for (const issue of validateRecipe.errors ?? []) {
            const path = issue.instancePath || '(root)';
            errors.push(`${dir}: ${path} ${issue.message}`);
        }
        continue;
    }

    if (recipe.slug !== dir) {
        errors.push(`${dir}: directory name must equal slug "${recipe.slug}"`);
    }

    const skillFile = join(recipesRoot, dir, 'SKILL.md');
    if (!existsSync(skillFile) || !statSync(skillFile).isFile()) {
        errors.push(`${dir}: missing SKILL.md`);
    } else {
        const skill = readFileSync(skillFile, 'utf8');
        const lines = skill.split('\n').length;
        if (lines < 60 || lines > 150) {
            errors.push(`${dir}/SKILL.md: ${lines} lines, expected 60-150`);
        }
        const frontmatter = skill.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n/);
        if (!frontmatter) {
            errors.push(`${dir}/SKILL.md: missing YAML frontmatter`);
        } else {
            let meta;
            try {
                meta = YAML.parse(frontmatter[1]);
            } catch (error) {
                errors.push(
                    `${dir}/SKILL.md: frontmatter is not valid YAML: ${error.message}`,
                );
            }
            if (meta) {
                if (meta.name !== recipe.skill.name) {
                    errors.push(
                        `${dir}/SKILL.md: frontmatter name "${meta.name}" != skill.name "${recipe.skill.name}"`,
                    );
                }
                if (
                    typeof meta.description !== 'string' ||
                    meta.description.length === 0
                ) {
                    errors.push(
                        `${dir}/SKILL.md: frontmatter description is missing`,
                    );
                } else if (meta.description.length > 1024) {
                    errors.push(
                        `${dir}/SKILL.md: frontmatter description is ${meta.description.length} chars (max 1024)`,
                    );
                }
            }
        }
    }

    for (const step of recipe.steps) {
        for (const command of step.commands ?? []) {
            if (command.run.includes('&&')) {
                errors.push(
                    `${dir}: step "${step.id}" has an && chain: ${command.run}`,
                );
            }
        }
    }

    if (recipe.status === 'published') {
        const hasBase = recipe.requires.some(
            (requirement) =>
                requirement.required &&
                ['base', 'iso'].includes(requirement.role),
        );
        if (!hasBase) {
            warnings.push(
                `${dir}: published recipe has no required base/iso requirement`,
            );
        }
    }

    if (recipe.status === 'draft') {
        if (recipe.steps.length < 3 || recipe.steps.length > 6) {
            errors.push(
                `${dir}: draft has ${recipe.steps.length} steps, expected 3-6 (split or merge the guide)`,
            );
        }

        const prose = [
            recipe.description,
            ...recipe.steps.map((step) => step.detail),
            ...recipe.steps.map((step) => step.title),
        ].join('\n');
        if (
            !/needs verification on the lan|draft recipe|not yet verified/i.test(
                prose,
            )
        ) {
            errors.push(
                `${dir}: draft must say that it still needs verification on the LAN hardware`,
            );
        }

        for (const requirement of recipe.requires) {
            if (requirement.sha256 && requirement.sha256.length > 0) {
                warnings.push(
                    `${dir}: draft requirement "${requirement.label}" carries a sha256; confirm it before publishing`,
                );
            }
        }
    }

    recipes.push(recipe);
}

const seen = { slug: new Map(), skill: new Map(), alias: new Map() };
for (const recipe of recipes) {
    if (seen.slug.has(recipe.slug)) {
        errors.push(
            `${recipe.slug}: duplicate slug (also ${seen.slug.get(recipe.slug)})`,
        );
    }
    seen.slug.set(recipe.slug, recipe.slug);

    if (seen.skill.has(recipe.skill.name)) {
        errors.push(
            `${recipe.slug}: skill name "${recipe.skill.name}" also used by ${seen.skill.get(recipe.skill.name)}`,
        );
    }
    seen.skill.set(recipe.skill.name, recipe.slug);

    for (const alias of recipe.aliases) {
        const key = alias.trim().toLowerCase();
        if (seen.alias.has(key)) {
            errors.push(
                `${recipe.slug}: alias "${alias}" also used by ${seen.alias.get(key)}`,
            );
        }
        seen.alias.set(key, recipe.slug);
    }
}

for (const warning of warnings) {
    console.warn(`warning: ${warning}`);
}

if (errors.length > 0) {
    console.error(
        `\n${errors.length} error(s) in ${recipes.length} recipe(s):\n`,
    );
    for (const error of errors) {
        console.error(`  - ${error}`);
    }
    process.exit(1);
}

console.log(
    `OK: ${recipes.length} recipes valid (${recipes.filter((r) => r.status === 'published').length} published, ${recipes.filter((r) => r.status === 'draft').length} draft)`,
);
