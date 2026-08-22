#!/usr/bin/env bash
set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Helper functions
error() {
    echo -e "${RED}Error: $1${NC}" >&2
    exit 1
}

info() {
    echo -e "${BLUE}$1${NC}"
}

success() {
    echo -e "${GREEN}$1${NC}"
}

warning() {
    echo -e "${YELLOW}$1${NC}"
}

# Check if we're in a git repository
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    error "Not in a git repository"
fi

# Get the diff content
info "Checking for changes..."
DIFF_CONTENT=""
DIFF_SOURCE=""

# Try staged changes first
if git diff --staged --quiet; then
    # No staged changes, try unstaged
    if git diff --quiet; then
        error "No changes found. Stage some changes with 'git add' or make changes to your files."
    else
        DIFF_CONTENT=$(git diff .)
        DIFF_SOURCE="unstaged changes"
        warning "No staged changes found, using unstaged changes"
    fi
else
    DIFF_CONTENT=$(git diff --staged .)
    DIFF_SOURCE="staged changes"
    success "Found staged changes"
fi

# Check if diff is empty
if [ -z "$DIFF_CONTENT" ]; then
    error "No changes found"
fi

# Create temporary file for analysis
TMP_DIR=$(mktemp -d)
CHANGED_DOCS_FILE="$TMP_DIR/changed_docs.md"
trap 'rm -rf "$TMP_DIR"' EXIT

info "Analyzing changes with Claude Code..."

# Create the analysis prompt
cat > "$TMP_DIR/analysis_prompt.txt" <<EOF
Analyze the following git diff and create a concise summary of what changed and how it might affect the documentation:

\`\`\`diff
$DIFF_CONTENT
\`\`\`

Please provide:
1. A brief summary of the changes (2-3 sentences)
2. Which aspects of the codebase were affected (e.g., controllers, services, configuration)
3. Specific documentation topics that should be updated

Format your response as a markdown document.
EOF

# Use Claude Code to analyze the changes
# Note: This assumes 'claude' CLI is available in the system
if command -v claude &> /dev/null; then
    claude chat --message "$(cat "$TMP_DIR/analysis_prompt.txt")" > "$CHANGED_DOCS_FILE" 2>&1 || {
        error "Failed to analyze changes with Claude Code"
    }
else
    error "Claude Code CLI not found. Please install it first."
fi

# Show the analysis and prompt for action
echo ""
info "=== Change Analysis ==="
cat "$CHANGED_DOCS_FILE"
echo ""

# Prompt user for action
while true; do
    echo -e "${YELLOW}What would you like to do?${NC}"
    echo "  y - Accept and continue to update documentation"
    echo "  n - Cancel"
    echo "  e - Edit analysis in \$EDITOR"
    read -p "Choice [y/n/e]: " choice

    case "$choice" in
        y|Y)
            success "Proceeding with documentation updates..."
            break
            ;;
        n|N)
            warning "Cancelled by user"
            exit 0
            ;;
        e|E)
            EDITOR="${EDITOR:-vi}"
            "$EDITOR" "$CHANGED_DOCS_FILE"
            echo ""
            info "=== Updated Change Analysis ==="
            cat "$CHANGED_DOCS_FILE"
            echo ""
            ;;
        *)
            warning "Invalid choice. Please enter y, n, or e"
            ;;
    esac
done

# Find all markdown files in docs/
info "Scanning documentation files..."
mapfile -t DOC_FILES < <(find docs -name "*.md" -type f | sort)

if [ ${#DOC_FILES[@]} -eq 0 ]; then
    error "No markdown files found in docs/ directory"
fi

# Show available documentation files
echo ""
info "=== Available Documentation Files ==="
for i in "${!DOC_FILES[@]}"; do
    echo "  $((i+1)). ${DOC_FILES[$i]}"
done
echo ""

# Suggest affected files based on the analysis
info "Analyzing which documentation files should be updated..."

# Create a prompt for Claude to suggest which files to update
cat > "$TMP_DIR/suggest_files_prompt.txt" <<EOF
Based on this change analysis:

$(cat "$CHANGED_DOCS_FILE")

And these available documentation files:
$(printf '%s\n' "${DOC_FILES[@]}")

Which documentation files should be updated?

Please respond with ONLY a JSON array of file paths, like:
["docs/index.md", "docs/guides/quickstart.md"]

If no files need updating, respond with an empty array: []
EOF

if command -v claude &> /dev/null; then
    SUGGESTED_FILES_JSON=$(claude chat --message "$(cat "$TMP_DIR/suggest_files_prompt.txt")" 2>&1)

    # Extract JSON array (look for ["..."] pattern)
    SUGGESTED_FILES=$(echo "$SUGGESTED_FILES_JSON" | grep -o '\[.*\]' | head -1 || echo "[]")

    if [ "$SUGGESTED_FILES" != "[]" ]; then
        success "Suggested files to update:"
        echo "$SUGGESTED_FILES" | jq -r '.[]' | while read -r file; do
            echo "  - $file"
        done
        echo ""
    else
        warning "No specific files suggested for update"
    fi
fi

# Prompt for each file or batch update
echo -e "${YELLOW}How would you like to proceed?${NC}"
echo "  1 - Update all suggested files automatically"
echo "  2 - Choose files interactively"
echo "  3 - Cancel"
read -p "Choice [1/2/3]: " update_choice

FILES_TO_UPDATE=()
case "$update_choice" in
    1)
        if [ "$SUGGESTED_FILES" == "[]" ]; then
            warning "No files were suggested. Switching to interactive mode."
            update_choice="2"
        else
            mapfile -t FILES_TO_UPDATE < <(echo "$SUGGESTED_FILES" | jq -r '.[]')
        fi
        ;;
    2)
        ;;
    3)
        warning "Cancelled by user"
        exit 0
        ;;
    *)
        warning "Invalid choice. Exiting."
        exit 1
        ;;
esac

if [ "$update_choice" == "2" ]; then
    for doc_file in "${DOC_FILES[@]}"; do
        read -p "Update $doc_file? [y/N]: " update_file
        if [[ "$update_file" =~ ^[Yy]$ ]]; then
            FILES_TO_UPDATE+=("$doc_file")
        fi
    done
fi

# Update selected files
if [ ${#FILES_TO_UPDATE[@]} -eq 0 ]; then
    warning "No files selected for update"
    exit 0
fi

info "Updating ${#FILES_TO_UPDATE[@]} file(s)..."

for doc_file in "${FILES_TO_UPDATE[@]}"; do
    info "Updating: $doc_file"

    # Create update prompt
    cat > "$TMP_DIR/update_prompt.txt" <<EOF
You are a concise technical writer. Update the following documentation file based on the change analysis.

**Change Analysis:**
$(cat "$CHANGED_DOCS_FILE")

**Git Diff ($DIFF_SOURCE):**
\`\`\`diff
$DIFF_CONTENT
\`\`\`

**Current Documentation ($doc_file):**
$([ -f "$doc_file" ] && cat "$doc_file" || echo "# New Documentation File")

**Instructions:**
- Update only the sections that are affected by the changes
- Keep the existing structure and unrelated content unchanged
- Add new sections if necessary
- Maintain the same markdown formatting style
- Be concise and accurate

Please provide the complete updated documentation file.
EOF

    if command -v claude &> /dev/null; then
        UPDATED_CONTENT=$(claude chat --message "$(cat "$TMP_DIR/update_prompt.txt")" 2>&1)

        # Save to temporary file first
        mkdir -p "$(dirname "$doc_file")"
        echo "$UPDATED_CONTENT" > "${doc_file}.new"

        # Show diff if file exists
        if [ -f "$doc_file" ]; then
            echo ""
            info "Changes to $doc_file:"
            diff -u "$doc_file" "${doc_file}.new" || true
            echo ""
            read -p "Apply these changes? [y/N]: " apply_changes
            if [[ "$apply_changes" =~ ^[Yy]$ ]]; then
                mv "${doc_file}.new" "$doc_file"
                success "Updated: $doc_file"
            else
                rm "${doc_file}.new"
                warning "Skipped: $doc_file"
            fi
        else
            mv "${doc_file}.new" "$doc_file"
            success "Created: $doc_file"
        fi
    else
        error "Claude Code CLI not found"
    fi
done

success "Documentation update complete!"
